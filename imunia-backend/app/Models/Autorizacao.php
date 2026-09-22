<?php

namespace App\Models;

use Database\Factories\AutorizacaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Autorização nominal de acesso ao histórico de um animal (RF36, decisoes.md
 * §4). É o registro de um ato de vontade do tutor, e por isso permanente: nem a
 * expiração nem a revogação apagam a linha (RN41).
 *
 * A situação não é coluna: é o que a data diz. Vigência, aviso de expiração e
 * encerramento se deduzem de `expira_em` e `revogada_em`, e é por isso que
 * ninguém precisa de rotina noturna para expirar autorização — uma data no
 * passado já é o fim do acesso (RF40a).
 */
#[Fillable([
    'animal_id',
    'prestador_id',
    'concedida_por_user_id',
    'concedida_em',
    'expira_em',
    'revogada_em',
])]
class Autorizacao extends Model
{
    /** @use HasFactory<AutorizacaoFactory> */
    use HasFactory;

    protected $table = 'autorizacoes_acesso';

    /**
     * RN39 — noventa dias, renováveis. Fica aqui, e não espalhado por quem
     * concede, para que a renovação de RF39 conte o mesmo prazo que a concessão.
     */
    public const PRAZO_DIAS = 90;

    /**
     * Antecedência com que a autorização vigente passa a se anunciar como "a
     * expirar" — âmbar em T12 e alerta na ficha do veterinário (V06). Sai do
     * briefing (§5.2, variante "a expirar" do `AuthorizationCard`): quinze dias
     * é o que dá tempo de renovar antes que o acesso caia.
     */
    public const DIAS_PARA_AVISAR_EXPIRACAO = 15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'concedida_em' => 'datetime',
            'expira_em' => 'datetime',
            'revogada_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Animal, Autorizacao>
     */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /**
     * @return BelongsTo<Prestador, Autorizacao>
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    /**
     * O tutor que concedeu, como usuário: é dele o ato, e é a ele que RF53
     * presta contas.
     *
     * @return BelongsTo<User, Autorizacao>
     */
    public function concedidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concedida_por_user_id');
    }

    /**
     * Vigência: nunca revogada (RN40) e ainda dentro do prazo (RN39). As duas
     * condições juntas em um lugar só, porque toda consulta do prestador
     * depende delas e uma delas esquecida é acesso indevido.
     *
     * @param Builder<Autorizacao> $consulta
     */
    #[Scope]
    protected function vigente(Builder $consulta): void
    {
        $consulta->whereNull('revogada_em')->where('expira_em', '>', now());
    }

    public function estaVigente(): bool
    {
        return $this->revogada_em === null && $this->expira_em->isFuture();
    }

    /**
     * Quantos dias faltam para o fim do prazo. Negativo depois dele, que é o que
     * permite a T12 dizer há quanto tempo a autorização caiu sem uma segunda
     * conta.
     */
    public function diasRestantes(): int
    {
        return (int) Carbon::today()->diffInDays($this->expira_em, absolute: false);
    }

    /**
     * A situação que a tela desenha (RF41). Quatro, e não duas, porque "a
     * expirar" e "expirada" pedem do tutor coisas diferentes — renovar em um
     * toque ou autorizar de novo pelo fluxo inteiro —, e "revogada" precisa
     * dizer que o fim foi ato dele.
     *
     * @return 'vigente'|'a_expirar'|'expirada'|'revogada'
     */
    public function situacao(): string
    {
        if ($this->revogada_em !== null) {
            return 'revogada';
        }

        if (! $this->expira_em->isFuture()) {
            return 'expirada';
        }

        return $this->diasRestantes() <= self::DIAS_PARA_AVISAR_EXPIRACAO ? 'a_expirar' : 'vigente';
    }

    /**
     * Quanto do prazo ainda resta, de 0 a 1 — a barra de T12.
     *
     * A barra mede o que sobra, e não o que passou: ela encolhe junto com o
     * número que está ao lado dela ("Restam 70 dias"), e uma barra que crescesse
     * enquanto o prazo acaba diria o contrário do texto que acompanha.
     */
    public function proporcaoRestante(): float
    {
        $total = (float) $this->concedida_em->diffInDays($this->expira_em, absolute: true);

        if ($total <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->diasRestantes() / $total));
    }
}
