<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ligação de confirmação de endereço (RF05), de uso único e com prazo (RN04).
 */
#[Fillable(['user_id', 'email', 'token', 'expira_em', 'usado_em'])]
class EmailVerificationToken extends Model
{
    /**
     * Vinte e quatro horas, prazo anunciado ao usuário na tela de ligação
     * expirada (P08).
     */
    public const VALIDADE_EM_HORAS = 24;

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'usado_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, EmailVerificationToken>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Emite uma ligação nova e descarta as anteriores do mesmo usuário: o
     * reenvio invalida o que foi enviado antes, de modo que só a mensagem mais
     * recente funcione.
     */
    public static function emitirPara(User $usuario): string
    {
        $token = Str::random(64);

        DB::transaction(function () use ($usuario, $token) {
            static::query()->where('user_id', $usuario->id)->whereNull('usado_em')->delete();

            static::create([
                'user_id' => $usuario->id,
                'email' => $usuario->email,
                'token' => hash('sha256', $token),
                'expira_em' => Carbon::now()->addHours(self::VALIDADE_EM_HORAS),
            ]);
        });

        return $token;
    }

    /**
     * O valor recebido do usuário nunca é comparado diretamente com o que está
     * gravado: a busca é pelo resumo, para que o vazamento da tabela não
     * entregue ligações utilizáveis.
     */
    public static function localizar(string $token): ?self
    {
        return static::query()->where('token', hash('sha256', $token))->first();
    }

    public function expirou(): bool
    {
        return $this->expira_em->isPast();
    }

    public function foiUsado(): bool
    {
        return $this->usado_em !== null;
    }
}
