<?php

namespace App\Models;

use App\Notifications\ConfirmacaoDeEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Prestador, User>
     */
    public function prestadores(): BelongsToMany
    {
        return $this->belongsToMany(Prestador::class, 'prestador_usuario')
            ->withPivot('papel', 'crmv', 'crmv_uf')
            ->withTimestamps();
    }

    /**
     * @return HasOne<Tutor, User>
     */
    public function tutor(): HasOne
    {
        return $this->hasOne(Tutor::class);
    }

    /**
     * @return HasMany<EmailVerificationToken, User>
     */
    public function tokensDeVerificacao(): HasMany
    {
        return $this->hasMany(EmailVerificationToken::class);
    }

    /**
     * Papéis acumuláveis do usuário (RN05): o vínculo com prestador é o que
     * confere `veterinario` e `admin_prestador`; o registro em `tutores`, o
     * papel `tutor`. Um mesmo usuário pode ter os três.
     *
     * @return list<string>
     */
    public function papeis(): array
    {
        $papeis = $this->prestadores
            ->map(fn (Prestador $prestador) => (string) $prestador->pivot->papel)
            ->all();

        if ($this->tutor()->exists()) {
            $papeis[] = 'tutor';
        }

        return array_values(array_unique($papeis));
    }

    /**
     * Painel de destino após a autenticação (RF01a). Enquanto o contexto ativo
     * não é persistido entre sessões, quem acumula papéis cai no ambiente de
     * registro, o de uso diário; o alternador de papel fica na interface.
     */
    public function rotaInicial(): string
    {
        $papeis = $this->papeis();

        if (in_array('veterinario', $papeis, true)) {
            return '/clinica/painel';
        }

        if (in_array('admin_prestador', $papeis, true)) {
            return '/prestador';
        }

        return '/inicio';
    }

    /**
     * A ligação de confirmação aponta para o SPA, não para a API: quem abre o
     * e-mail precisa cair numa tela, e não num JSON (P08).
     */
    public function sendEmailVerificationNotification(): void
    {
        $token = EmailVerificationToken::emitirPara($this);

        $this->notify(new ConfirmacaoDeEmail($token));
    }
}
