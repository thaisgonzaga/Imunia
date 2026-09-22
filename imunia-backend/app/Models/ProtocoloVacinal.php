<?php

namespace App\Models;

use Database\Factories\ProtocoloVacinalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parâmetros temporais do calendário vacinal de um imunobiológico, dentro de
 * uma versão (RF24). Fica versionado de propósito: RN32 proíbe que a
 * publicação de uma versão nova recalcule retroativamente datas já emitidas,
 * e é por isso que cada `Vacinacao` guarda qual linha destas estava vigente ao
 * aplicar, em vez de sempre consultar "a versão atual".
 */
#[Fillable([
    'imunobiologico_id',
    'versao_protocolo_id',
    'numero_doses_serie_primaria',
    'intervalo_minimo_dias',
    'intervalo_maximo_dias',
    'idade_minima_dose_final_semanas',
    'idade_minima_primeira_dose_semanas',
    'reforco_inicial_meses',
    'periodicidade_revacinacao_meses',
    'limite_atraso_dias',
    'conduta_apos_limite',
    'doses_adulto_sem_historico',
])]
class ProtocoloVacinal extends Model
{
    /** @use HasFactory<ProtocoloVacinalFactory> */
    use HasFactory;

    protected $table = 'protocolos_vacinais';

    /** RF27 — prosseguir a série de onde parou, com dose única de reforço. */
    public const PROSSEGUIR = 'prosseguir';

    /** RF27 — recomeçar a série primária do início. */
    public const REINICIAR = 'reiniciar';

    /**
     * @return BelongsTo<Imunobiologico, ProtocoloVacinal>
     */
    public function imunobiologico(): BelongsTo
    {
        return $this->belongsTo(Imunobiologico::class);
    }

    /**
     * @return BelongsTo<VersaoProtocolo, ProtocoloVacinal>
     */
    public function versaoProtocolo(): BelongsTo
    {
        return $this->belongsTo(VersaoProtocolo::class);
    }

    /**
     * O rótulo da versão, lido de onde ele agora mora. Continua sendo
     * `$vacinacao->protocoloVacinal->versao` para quem exibe a procedência do
     * cálculo (T06, RF26b) — o dado mudou de lugar, não de nome.
     *
     * O agendamento próprio de uma clínica (A04) não pertence a versão
     * publicada alguma, e o texto diz isso em vez de deixar a frase da regra
     * terminar em "protocolo ." — a procedência do cálculo é justamente o que
     * o veterinário de outro prestador precisa saber ao ler a carteira.
     *
     * @return Attribute<string, never>
     */
    protected function versao(): Attribute
    {
        return Attribute::get(fn () => $this->versaoProtocolo?->rotulo ?? 'próprio da clínica');
    }

    /**
     * RN34 — o intervalo entre doses da série primária é uma janela (duas a
     * quatro semanas), não um número único. O cálculo usa o ponto médio como
     * previsão, e é o que a nota do VaccineRail anuncia ao tutor (RF26b).
     */
    public function intervaloPrevistoDias(): int
    {
        return (int) round(($this->intervalo_minimo_dias + $this->intervalo_maximo_dias) / 2);
    }

    /**
     * RN35 — o primeiro reforço tem prazo próprio quando a diretriz o
     * distingue da revacinação seguinte; quando não distingue, é a mesma
     * periodicidade. Nulo quando não há revacinação alguma a prever.
     */
    public function primeiroReforcoMeses(): ?int
    {
        return $this->reforco_inicial_meses ?? $this->periodicidade_revacinacao_meses;
    }

    /**
     * "Dose única, sem revacinação" — o agendamento que não se repete, que
     * A04 permite a uma clínica declarar para um imunobiológico próprio.
     *
     * Nenhuma linha versionada da plataforma cai neste caso: a diretriz que
     * X02 mantém sempre diz de quanto em quanto tempo revacinar, e
     * `SalvarParametrosProtocoloRequest` mantém o campo obrigatório lá.
     */
    public function semRevacinacao(): bool
    {
        return $this->periodicidade_revacinacao_meses === null;
    }

    /**
     * A conduta em linguagem de quem vai lê-la na tela, e não a chave gravada.
     * RF27c e RN36: é sugestão exibida ao profissional, nunca impedimento.
     */
    public function descricaoDaConduta(): string
    {
        return $this->conduta_apos_limite === self::REINICIAR
            ? 'reiniciar a série primária'
            : 'prosseguir com dose única';
    }
}
