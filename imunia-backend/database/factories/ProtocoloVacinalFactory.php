<?php

namespace Database\Factories;

use App\Models\Imunobiologico;
use App\Models\ProtocoloVacinal;
use App\Models\VersaoProtocolo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Parâmetros da V10 múltipla canina — RN33 (16 semanas), RN34 (21 dias de
 * intervalo, ponto médio da janela de 14-28) e RN35 (reforço anual, mesmo
 * texto do Claude Design).
 *
 * @extends Factory<ProtocoloVacinal>
 */
class ProtocoloVacinalFactory extends Factory
{
    protected $model = ProtocoloVacinal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'imunobiologico_id' => Imunobiologico::factory(),
            // Os parâmetros de imunobiológicos diferentes convivem sob a mesma
            // versão vigente — é isso que uma versão é. Só quando não houver
            // nenhuma é que a factory cria a primeira.
            'versao_protocolo_id' => VersaoProtocolo::query()
                ->where('situacao', VersaoProtocolo::VIGENTE)
                ->value('id') ?? VersaoProtocolo::factory(),
            'numero_doses_serie_primaria' => 3,
            'intervalo_minimo_dias' => 14,
            'intervalo_maximo_dias' => 28,
            'idade_minima_dose_final_semanas' => 16,
            'idade_minima_primeira_dose_semanas' => 6,
            'reforco_inicial_meses' => null,
            'periodicidade_revacinacao_meses' => 12,
            'limite_atraso_dias' => 30,
            'conduta_apos_limite' => ProtocoloVacinal::PROSSEGUIR,
            'doses_adulto_sem_historico' => 2,
        ];
    }

    /**
     * Antirrábica — dose única, sem série primária de múltiplas doses.
     */
    public function antirrabica(): static
    {
        return $this->state(fn () => [
            'numero_doses_serie_primaria' => 1,
            'intervalo_minimo_dias' => 0,
            'intervalo_maximo_dias' => 0,
            'idade_minima_dose_final_semanas' => null,
            'idade_minima_primeira_dose_semanas' => 12,
            'reforco_inicial_meses' => null,
            'periodicidade_revacinacao_meses' => 12,
            'doses_adulto_sem_historico' => 1,
        ]);
    }
}
