<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A confirmação por código de uso único que precede a concessão (RF37, RN38).
 *
 * O requisito não é uma formalidade de segurança acrescentada à concessão: é a
 * concessão. Sem ele, o balcão da clínica que está com o telefone do tutor na
 * mão conduz o fluxo inteiro e obtém autorização sem ato de vontade do titular.
 * O código sai por um canal que só o tutor abre, e é por isso que ele nunca
 * aparece em tela nem viaja em resposta alguma desta API (RF37c).
 */
#[Fillable([
    'publico',
    'user_id',
    'prestador_id',
    'animais',
    'codigo',
    'enviado_em',
    'expira_em',
    'tentativas',
    'bloqueada_ate',
    'confirmada_em',
])]
#[Hidden(['codigo'])]
class ConfirmacaoDeAutorizacao extends Model
{
    protected $table = 'confirmacoes_de_autorizacao';

    /**
     * Prazo curto de RF37a. Cinco minutos é o que o mockup de T11 anuncia no
     * contador ("válido por 4 min 12 s", logo depois do envio) e o que a família
     * de telas de autenticação já pratica: prazo bastante para abrir o e-mail,
     * curto demais para deixar o código útil esquecido na caixa de entrada.
     */
    public const VALIDADE_EM_MINUTOS = 5;

    /**
     * Tentativas antes da pausa (RF37a). Cinco, como o bloqueio de entrada de
     * P02 — e pelo mesmo motivo: o dígito errado acontece, a quinta repetição
     * já não é engano.
     */
    public const TENTATIVAS = 5;

    /**
     * A pausa que a quinta tentativa errada abre. Vale para o tutor inteiro, e
     * não só para esta linha: bloquear apenas a confirmação em curso seria
     * bloquear nada, bastando começar outra do zero.
     */
    public const BLOQUEIO_EM_MINUTOS = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'animais' => 'array',
            'enviado_em' => 'datetime',
            'expira_em' => 'datetime',
            'bloqueada_ate' => 'datetime',
            'confirmada_em' => 'datetime',
        ];
    }

    /**
     * O ULID na URL, nunca o id: ver o comentário da migration.
     */
    public function getRouteKeyName(): string
    {
        return 'publico';
    }

    /**
     * @return BelongsTo<User, ConfirmacaoDeAutorizacao>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Prestador, ConfirmacaoDeAutorizacao>
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    /**
     * Abre uma confirmação e devolve o código em claro — a única vez em que ele
     * existe fora do e-mail. Quem chama entrega-o à notificação e o descarta;
     * nada o guarda, nem sequer o registro que acabou de nascer.
     *
     * As confirmações anteriores do mesmo usuário vencem no ato. Duas em aberto
     * significariam dois códigos válidos, e o tutor que digitasse o da mensagem
     * antiga concederia o que a mensagem nova propõe — que pode ser outro
     * prestador. As linhas permanecem, apenas vencidas: RN41 conserva o
     * histórico, e uma tentativa abandonada também é fato do expediente.
     *
     * @param  list<int>  $animais
     * @return array{0: self, 1: string}
     */
    public static function abrir(User $usuario, Prestador $prestador, array $animais): array
    {
        static::query()
            ->where('user_id', $usuario->id)
            ->whereNull('confirmada_em')
            ->where('expira_em', '>', Carbon::now())
            ->update(['expira_em' => Carbon::now()]);

        $codigo = self::sortearCodigo();

        $confirmacao = static::create([
            'publico' => (string) Str::ulid(),
            'user_id' => $usuario->id,
            'prestador_id' => $prestador->id,
            'animais' => array_values($animais),
            'codigo' => self::resumir($codigo),
            'enviado_em' => Carbon::now(),
            'expira_em' => Carbon::now()->addMinutes(self::VALIDADE_EM_MINUTOS),
        ]);

        return [$confirmacao, $codigo];
    }

    /**
     * Emite código novo na mesma confirmação, com prazo novo.
     *
     * O contador de tentativas não volta a zero: se voltasse, quatro erros
     * seguidos e um reenvio deixariam o limite de RF37a inalcançável, e o
     * bloqueio nunca aconteceria. Só o prazo é renovado — é essa a promessa que
     * a tela faz ao liberar o reenvio quando o contador termina.
     */
    public function reenviar(): string
    {
        $codigo = self::sortearCodigo();

        $this->forceFill([
            'codigo' => self::resumir($codigo),
            'enviado_em' => Carbon::now(),
            'expira_em' => Carbon::now()->addMinutes(self::VALIDADE_EM_MINUTOS),
        ])->save();

        return $codigo;
    }

    /**
     * A comparação é entre resumos e em tempo constante. O que o tutor digitou
     * nunca é comparado ao que está gravado, porque o que está gravado não é o
     * código.
     */
    public function codigoConfere(string $digitado): bool
    {
        return hash_equals($this->codigo, self::resumir($digitado));
    }

    /**
     * Contabiliza o erro e, na última tentativa, abre a pausa.
     */
    public function registrarErro(): void
    {
        $this->tentativas++;

        if ($this->tentativas >= self::TENTATIVAS) {
            $this->bloqueada_ate = Carbon::now()->addMinutes(self::BLOQUEIO_EM_MINUTOS);
        }

        $this->save();
    }

    public function expirou(): bool
    {
        return $this->expira_em->isPast();
    }

    public function foiConfirmada(): bool
    {
        return $this->confirmada_em !== null;
    }

    public function bloqueada(): bool
    {
        return $this->bloqueada_ate !== null && $this->bloqueada_ate->isFuture();
    }

    public function tentativasRestantes(): int
    {
        return max(0, self::TENTATIVAS - $this->tentativas);
    }

    /**
     * O contador da tela, em segundos. Sai do relógio do servidor porque é ele
     * que decide a expiração — o do navegador serve para animar a contagem, não
     * para determiná-la.
     */
    public function segundosRestantes(): int
    {
        return max(0, (int) Carbon::now()->diffInSeconds($this->expira_em, false));
    }

    public function segundosDeBloqueio(): int
    {
        if ($this->bloqueada_ate === null) {
            return 0;
        }

        return max(0, (int) Carbon::now()->diffInSeconds($this->bloqueada_ate, false));
    }

    /**
     * A pausa em curso do tutor, se houver — a de qualquer confirmação sua, e
     * não apenas a da que ele tem aberta agora (ver BLOQUEIO_EM_MINUTOS).
     */
    public static function bloqueioDe(User $usuario): ?self
    {
        return static::query()
            ->where('user_id', $usuario->id)
            ->where('bloqueada_ate', '>', Carbon::now())
            ->latest('bloqueada_ate')
            ->first();
    }

    /**
     * Seis dígitos, sorteados por gerador criptográfico. `str_pad` porque
     * "004821" é um código de seis casas como qualquer outro, e descartá-lo
     * reduziria o espaço de busca sem que ninguém notasse.
     */
    private static function sortearCodigo(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function resumir(string $codigo): string
    {
        return hash('sha256', $codigo);
    }
}
