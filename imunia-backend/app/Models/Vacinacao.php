<?php

namespace App\Models;

use Database\Factories\VacinacaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Registro de aplicação de vacina (RF25) ou de histórico pregresso não
 * verificado (RF29). É registro clínico: imutável após criado (RN26,
 * decisoes.md §3.4) — há três caminhos de escrita, e os três só criam: o
 * lançamento pregresso de T09, a aplicação profissional de V07 e a retificação
 * de V09, que grava uma linha nova apontando para a que corrige.
 *
 * Como em `Atendimento`, a imutabilidade não é imposta por este modelo: ela é a
 * ausência de qualquer caminho de escrita sobre registro existente. Não há
 * `update` a chamar porque corrigir é criar outro registro (RF33).
 */
#[Fillable([
    'animal_id',
    'retifica_vacinacao_id',
    'motivo_retificacao',
    'prestador_id',
    'imunobiologico_id',
    'protocolo_vacinal_id',
    'origem',
    'fabricante',
    'lote',
    'validade',
    'via_administracao',
    'sitio_anatomico',
    'local_aplicacao',
    'aplicado_em',
    'data_aproximada',
    'ordem_dose',
    'ordem_dose_sugerida',
    'justificativa_conduta',
    'observacao',
    'aplicador_nome',
    'aplicador_crmv',
    'aplicador_user_id',
    'validade_expirada_confirmada',
    'lancado_por_user_id',
])]
class Vacinacao extends Model
{
    /** @use HasFactory<VacinacaoFactory> */
    use HasFactory;

    protected $table = 'vacinacoes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validade' => 'date',
            'aplicado_em' => 'datetime',
            'data_aproximada' => 'boolean',
            'validade_expirada_confirmada' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Animal, Vacinacao>
     */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /**
     * @return BelongsTo<Imunobiologico, Vacinacao>
     */
    public function imunobiologico(): BelongsTo
    {
        return $this->belongsTo(Imunobiologico::class);
    }

    /**
     * @return BelongsTo<ProtocoloVacinal, Vacinacao>
     */
    public function protocoloVacinal(): BelongsTo
    {
        return $this->belongsTo(ProtocoloVacinal::class);
    }

    /**
     * @return BelongsTo<Prestador, Vacinacao>
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    /**
     * @return BelongsTo<User, Vacinacao>
     */
    public function lancadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lancado_por_user_id');
    }

    /**
     * RN27 — o autor do ato clínico, e não apenas o seu nome no papel. É por
     * este identificador que a retificação (V09) saberá quem pode retificar;
     * `aplicador_nome` e `aplicador_crmv` continuam sendo o retrato do que
     * valia no dia, que é o que a carteira exibe.
     *
     * @return BelongsTo<User, Vacinacao>
     */
    public function aplicador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aplicador_user_id');
    }

    /**
     * A aplicação que esta corrige — nula, portanto, em todo registro que não
     * seja uma retificação (RF33).
     *
     * @return BelongsTo<Vacinacao, Vacinacao>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(Vacinacao::class, 'retifica_vacinacao_id');
    }

    /**
     * A correção deste registro, quando existir. O par com `original()` é o que
     * torna o encadeamento navegável nos dois sentidos (RF33b).
     *
     * @return HasOne<Vacinacao, Vacinacao>
     */
    public function retificacao(): HasOne
    {
        return $this->hasOne(Vacinacao::class, 'retifica_vacinacao_id');
    }

    public function ehRetificacao(): bool
    {
        return $this->retifica_vacinacao_id !== null;
    }

    /**
     * A versão que vale hoje de cada aplicação: a que ninguém corrigiu.
     *
     * Diferente de `atendimentos`, aqui o filtro não é cosmético. Uma aplicação
     * e a sua retificação descrevem **uma** dose — o que mudou foi o lote, o
     * sítio, a ordem declarada —, e sem este escopo a série passaria a contar
     * duas, deslocando o rótulo de todas as doses seguintes e a data da próxima
     * (RF26). O tutor veria a carteira ganhar uma dose porque alguém corrigiu o
     * número do lote.
     *
     * O que fica de fora daqui não desaparece: continua no histórico (RF35), no
     * encadeamento de T06 e alcançável pelo seu endereço próprio. Só não conta
     * como dose, porque não é uma segunda dose.
     *
     * @param  Builder<Vacinacao>  $consulta
     */
    #[Scope]
    protected function vigente(Builder $consulta): void
    {
        $consulta->whereDoesntHave('retificacao');
    }

    /**
     * RN24 — a marcação de não verificado nasce com o registro e é
     * permanente; não existe caminho de escrita que a remova.
     */
    public function pregresso(): bool
    {
        return $this->origem === 'pregresso';
    }
}
