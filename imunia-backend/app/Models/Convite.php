<?php

namespace App\Models;

use App\Notifications\ConviteDeAtivacao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Convite de ativação de acesso — do tutor cadastrado por um prestador (RF14)
 * e do médico-veterinário convidado por um administrador (RF09).
 */
#[Fillable(['user_id', 'prestador_id', 'convidado_por', 'tipo', 'token', 'expira_em', 'aceito_em'])]
class Convite extends Model
{
    use HasFactory;

    /**
     * Sete dias, prazo anunciado ao usuário na tela de convite expirado (P07).
     */
    public const VALIDADE_EM_DIAS = 7;

    protected $table = 'convites';

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'aceito_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, Convite>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Prestador, Convite>
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    /**
     * @return BelongsTo<User, Convite>
     */
    public function convidante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convidado_por');
    }

    /**
     * Emite o convite para uma conta que já existe: tanto o cadastro do tutor
     * pelo prestador quanto o vínculo do veterinário são criados antes, nas
     * telas que os originam. O convite entrega apenas o direito de definir a
     * própria senha.
     *
     * @return array{0: Convite, 1: string} o convite e o token em claro, que
     *                                      só existe neste retorno
     */
    public static function emitir(User $usuario, Prestador $prestador, string $tipo, ?User $convidante = null): array
    {
        $token = Str::random(64);

        $convite = static::create([
            'user_id' => $usuario->id,
            'prestador_id' => $prestador->id,
            'convidado_por' => $convidante?->id,
            'tipo' => $tipo,
            'token' => hash('sha256', $token),
            'expira_em' => Carbon::now()->addDays(self::VALIDADE_EM_DIAS),
        ]);

        return [$convite, $token];
    }

    /**
     * Renova o prazo e troca o token: o convite antigo deixa de valer no mesmo
     * ato em que o novo é enviado (RF14a).
     */
    public function reemitir(): string
    {
        $token = Str::random(64);

        $this->forceFill([
            'token' => hash('sha256', $token),
            'expira_em' => Carbon::now()->addDays(self::VALIDADE_EM_DIAS),
        ])->save();

        return $token;
    }

    public function enviar(string $token): void
    {
        $this->usuario->notify(new ConviteDeAtivacao($this, $token));
    }

    public static function localizar(string $token): ?self
    {
        return static::query()->where('token', hash('sha256', $token))->first();
    }

    public function expirou(): bool
    {
        return $this->expira_em->isPast();
    }

    public function foiAceito(): bool
    {
        return $this->aceito_em !== null;
    }
}
