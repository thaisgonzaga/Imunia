<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\ProtocoloVacinal;
use App\Models\Vacinacao;
use App\Services\CalendarioVacinalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O motor de cálculo (RF26, RN35), nos dois pontos onde a semeadura da WSAVA
 * 2024 o alcança.
 *
 * O primeiro é o rótulo do primeiro reforço. Enquanto nenhum protocolo
 * distinguia o prazo do primeiro reforço da periodicidade seguinte, a data e o
 * texto vinham do mesmo número e a contradição não tinha como aparecer. A
 * tríplice felina da WSAVA distingue — reforço por volta dos seis meses,
 * revacinação trienal —, e sem esta correção a carteira do tutor diria "Reforço
 * a cada 3 anos" ao lado de uma data de seis meses.
 *
 * O segundo é a ausência de revacinação, que A04 tornou possível: uma série que
 * acaba e não se repete. O motor precisa dizer que acabou, e não que não soube
 * calcular.
 */
class CalendarioVacinalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function calendario(): CalendarioVacinalService
    {
        return app(CalendarioVacinalService::class);
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    private function protocolo(array $parametros = []): ProtocoloVacinal
    {
        return ProtocoloVacinal::factory()->create($parametros);
    }

    public function test_rotulo_do_primeiro_reforco_corresponde_a_data_calculada(): void
    {
        $protocolo = $this->protocolo([
            'numero_doses_serie_primaria' => 3,
            'reforco_inicial_meses' => 6,
            'periodicidade_revacinacao_meses' => 36,
        ]);

        $previsao = $this->calendario()->preverDoseSeguinte(
            $protocolo,
            Carbon::parse('2026-01-10'),
            3,
        );

        $this->assertSame('2026-07-10', $previsao['data']->toDateString());
        $this->assertSame('Primeiro reforço', $previsao['rotulo']);
        $this->assertSame('reforco_inicial', $previsao['tipo']);

        // O texto tem de explicar a data que está ao lado dele, e anunciar a
        // periodicidade seguinte como o que vem *depois* — nunca como se fosse
        // a regra desta dose.
        $this->assertStringContainsString('Primeiro reforço 6 meses', $previsao['regra_texto']);
        $this->assertStringContainsString('Depois, reforço a cada 3 anos', $previsao['regra_texto']);
    }

    public function test_revacinacao_seguinte_usa_a_periodicidade_e_nao_o_reforco_inicial(): void
    {
        $protocolo = $this->protocolo([
            'numero_doses_serie_primaria' => 3,
            'reforco_inicial_meses' => 6,
            'periodicidade_revacinacao_meses' => 36,
        ]);

        $previsao = $this->calendario()->preverDoseSeguinte(
            $protocolo,
            Carbon::parse('2026-07-10'),
            4,
        );

        $this->assertSame('2029-07-10', $previsao['data']->toDateString());
        $this->assertSame('Reforço a cada 3 anos', $previsao['rotulo']);
        $this->assertSame('revacinacao', $previsao['tipo']);
    }

    public function test_protocolo_sem_reforco_inicial_proprio_mantem_o_texto_da_periodicidade(): void
    {
        $protocolo = $this->protocolo([
            'numero_doses_serie_primaria' => 3,
            'reforco_inicial_meses' => null,
            'periodicidade_revacinacao_meses' => 12,
        ]);

        $previsao = $this->calendario()->preverDoseSeguinte($protocolo, Carbon::parse('2026-01-10'), 3);

        $this->assertSame('2027-01-10', $previsao['data']->toDateString());
        $this->assertSame('Reforço anual', $previsao['rotulo']);
    }

    public function test_protocolo_sem_revacinacao_nao_preve_proxima_dose(): void
    {
        $protocolo = $this->protocolo([
            'numero_doses_serie_primaria' => 1,
            'reforco_inicial_meses' => null,
            'periodicidade_revacinacao_meses' => null,
        ]);

        $previsao = $this->calendario()->preverDoseSeguinte($protocolo, Carbon::parse('2026-01-10'), 1);

        $this->assertNull($previsao['data']);
        $this->assertSame('Série concluída', $previsao['rotulo']);
        $this->assertSame('serie_concluida', $previsao['tipo']);
        $this->assertStringContainsString('não prevê revacinação', $previsao['regra_texto']);
    }

    public function test_serie_concluida_nao_e_confundida_com_falta_de_data(): void
    {
        [$animal, $imunobiologico] = $this->animalComVacinaDeDoseUnica();

        $carteira = $this->calendario()->montarCarteira($animal);
        $grupo = $carteira['grupos'][0];

        $this->assertSame($imunobiologico->chave, $grupo['imunobiologico']['chave']);
        $this->assertNull($grupo['proxima_dose']);

        // "sem data suficiente para calcular" seria mentira: houve data, o
        // cálculo correu, e a resposta foi que não haverá outra dose.
        $this->assertSame('em-dia', $grupo['situacao']['tipo']);
        $this->assertSame('série concluída', $grupo['situacao']['texto']);
        $this->assertSame('concluída', $grupo['situacao']['texto_curto']);
    }

    public function test_serie_concluida_nao_entra_nas_proximas_doses(): void
    {
        [$animal] = $this->animalComVacinaDeDoseUnica();

        $carteira = $this->calendario()->montarCarteira($animal);

        // Nada a lembrar ao tutor: a dose que não existe não vira aviso (RF42).
        $this->assertSame([], $carteira['proximas_doses']);
        $this->assertSame(1, $carteira['resumo']['em_dia']);
    }

    /**
     * Um animal com uma única aplicação de uma vacina que não se repete — o
     * agendamento que A04 permite a uma clínica declarar.
     *
     * @return array{0: Animal, 1: Imunobiologico}
     */
    private function animalComVacinaDeDoseUnica(): array
    {
        $animal = Animal::factory()->create();

        $imunobiologico = Imunobiologico::factory()->create(['chave' => 'p1-vacina-da-casa']);

        $protocolo = ProtocoloVacinal::factory()->create([
            'imunobiologico_id' => $imunobiologico->id,
            'versao_protocolo_id' => null,
            'numero_doses_serie_primaria' => 1,
            'intervalo_minimo_dias' => 0,
            'intervalo_maximo_dias' => 0,
            'idade_minima_dose_final_semanas' => null,
            'reforco_inicial_meses' => null,
            'periodicidade_revacinacao_meses' => null,
        ]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonths(2),
            'data_aproximada' => false,
            'ordem_dose' => 1,
        ]);

        return [$animal, $imunobiologico];
    }
}
