<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Imunobiologico;
use App\Models\Notificacao;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendenciasVacinaisTest extends TestCase
{
    use RefreshDatabase;

    private function clinica(string $nome = 'Clínica Vet Amigo'): Prestador
    {
        return Prestador::factory()->create(['nome' => $nome]);
    }

    private function marcelo(Prestador ...$prestadores): User
    {
        $usuario = User::factory()->create(['name' => 'Marcelo Andrade']);

        foreach ($prestadores as $prestador) {
            $usuario->prestadores()->attach($prestador, [
                'papel' => 'veterinario',
                'crmv' => '12345',
                'crmv_uf' => 'MG',
            ]);
        }

        return $usuario;
    }

    private function animalAutorizado(Prestador $prestador, string $nome = 'Théo', array $atributos = []): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => "Tutor de {$nome}"]);
        $animal = Animal::factory()->create([...$atributos, 'tutor_id' => $tutor->id, 'nome' => $nome]);

        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        return $animal;
    }

    private function antirrabica(): Imunobiologico
    {
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        ProtocoloVacinal::factory()->antirrabica()->create(['imunobiologico_id' => $imunobiologico->id]);

        return $imunobiologico;
    }

    /**
     * Uma aplicação isolada de antirrábica. Como o reforço é anual, a data da
     * aplicação decide sozinha a situação: catorze meses atrás produz dose
     * atrasada há dois meses; onze meses e meio, dose a vencer em quinze dias.
     */
    private function aplicar(Animal $animal, Prestador $prestador, Imunobiologico $imunobiologico, string $quando): Vacinacao
    {
        return Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()->id,
            'aplicado_em' => $quando,
        ]);
    }

    public function test_a_consulta_exige_sessao(): void
    {
        $this->getJson('/api/clinica/pendencias')->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_alcanca_as_pendencias(): void
    {
        $tutor = Tutor::factory()->create();

        $this->actingAs($tutor->user)
            ->getJson('/api/clinica/pendencias')
            ->assertForbidden();
    }

    /**
     * RN08 — a rechamada por critério é da equipe clínica, e não do balcão. É a
     * consequência operacional assumida em §2.4 dos requisitos: a persona
     * negativa do sistema não tem esta tela.
     */
    public function test_o_administrador_do_prestador_nao_alcanca_as_pendencias(): void
    {
        $clinica = $this->clinica();
        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($clinica, ['papel' => 'admin_prestador']);

        $this->actingAs($usuario)
            ->getJson('/api/clinica/pendencias')
            ->assertForbidden();
    }

    /**
     * RF49c — "animais sem autorização vigente não figuram no resultado". A
     * dose está vencida, o prestador aplicou a anterior, e mesmo assim a linha
     * não existe: o que traz o animal para cá é a autorização do tutor.
     */
    public function test_animal_sem_autorizacao_vigente_nao_figura_no_resultado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $autorizado = $this->animalAutorizado($clinica, 'Théo');
        $this->aplicar($autorizado, $clinica, $antirrabica, now()->subMonths(14));

        $semAutorizacao = Animal::factory()->create(['nome' => 'Bidu']);
        $this->aplicar($semAutorizacao, $clinica, $antirrabica, now()->subMonths(14));

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/pendencias');

        $resposta->assertOk();
        $resposta->assertJsonPath('total', 1);
        $resposta->assertJsonPath('itens.0.animal.nome', 'Théo');
    }

    public function test_autorizacao_revogada_ou_expirada_nao_traz_a_pendencia(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        foreach (['revogada', 'expirada'] as $estado) {
            $animal = Animal::factory()->create(['nome' => "Animal {$estado}"]);
            Autorizacao::factory()->{$estado}()->create([
                'animal_id' => $animal->id,
                'prestador_id' => $clinica->id,
                'concedida_por_user_id' => $animal->tutor->user_id,
            ]);
            $this->aplicar($animal, $clinica, $antirrabica, now()->subMonths(14));
        }

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/pendencias')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('estado', 'sem_autorizacoes');
    }

    /**
     * O desenho de V02 ordena por dias de atraso, do maior para o menor: a
     * primeira linha é o animal que está esperando há mais tempo.
     */
    public function test_o_resultado_vem_ordenado_por_dias_de_atraso_decrescente(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $recente = $this->animalAutorizado($clinica, 'Kiko');
        $this->aplicar($recente, $clinica, $antirrabica, now()->subMonths(12)->subDays(5));

        $antigo = $this->animalAutorizado($clinica, 'Pipoca');
        $this->aplicar($antigo, $clinica, $antirrabica, now()->subMonths(14));

        $aVencer = $this->animalAutorizado($clinica, 'Mel');
        $this->aplicar($aVencer, $clinica, $antirrabica, now()->subMonths(12)->addDays(10));

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/pendencias');

        $resposta->assertJsonPath('total', 3);
        $resposta->assertJsonPath('itens.0.animal.nome', 'Pipoca');
        $resposta->assertJsonPath('itens.0.situacao.tipo', 'atrasada');
        $resposta->assertJsonPath('itens.1.animal.nome', 'Kiko');
        // A que ainda vai vencer fica por último, depois de todas as vencidas.
        $resposta->assertJsonPath('itens.2.animal.nome', 'Mel');
        $resposta->assertJsonPath('itens.2.situacao.tipo', 'proxima');
        $resposta->assertJsonPath('itens.2.atraso.vencida', false);
    }

    /**
     * A dose que o calendário considera tranquila não é pendência, ainda que a
     * janela de noventa dias a alcançasse por data.
     */
    public function test_dose_em_dia_nao_figura_entre_as_pendencias(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $animal = $this->animalAutorizado($clinica, 'Bidu');
        $this->aplicar($animal, $clinica, $antirrabica, now()->subDays(20));

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/pendencias?dias=90')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('animais_no_ambito', 1);
    }

    public function test_os_filtros_de_especie_imunobiologico_e_situacao_sao_combinaveis(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $v10 = Imunobiologico::factory()->create();
        ProtocoloVacinal::factory()->antirrabica()->create(['imunobiologico_id' => $v10->id]);

        $cao = $this->animalAutorizado($clinica, 'Pipoca', ['especie' => 'cao']);
        $this->aplicar($cao, $clinica, $antirrabica, now()->subMonths(14));
        $this->aplicar($cao, $clinica, $v10, now()->subMonths(14));

        $gato = $this->animalAutorizado($clinica, 'Mel', ['especie' => 'gato']);
        $this->aplicar($gato, $clinica, $antirrabica, now()->subMonths(14));

        $atuando = $this->actingAs($marcelo);

        // Três linhas ao todo — o cão comparece duas vezes, porque são duas
        // doses a marcar e não dois animais a chamar.
        $atuando->getJson('/api/clinica/pendencias')->assertJsonPath('total', 3);

        $atuando->getJson('/api/clinica/pendencias?especie=gato')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.animal.nome', 'Mel');

        $atuando->getJson('/api/clinica/pendencias?imunobiologico=v10-multipla-canina')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.animal.nome', 'Pipoca');

        // A combinação que o desenho prevê como "filtrado sem resultado": o
        // total sem filtros continua visível para que a tela possa dizer
        // "0 de 3 resultados" em vez de fingir que não há pendência alguma.
        $atuando->getJson('/api/clinica/pendencias?especie=gato&imunobiologico=v10-multipla-canina')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('total_sem_filtros', 3);

        $atuando->getJson('/api/clinica/pendencias?situacao=proxima')->assertJsonPath('total', 0);
        $atuando->getJson('/api/clinica/pendencias?situacao=atrasada')->assertJsonPath('total', 3);
    }

    /**
     * O período recorta a janela do que ainda vai vencer. O que já venceu
     * permanece em qualquer janela: uma dose atrasada não deixa de ser pendência
     * porque o profissional apertou o filtro.
     */
    public function test_o_periodo_recorta_o_que_esta_por_vencer_e_conserva_o_que_venceu(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $vencida = $this->animalAutorizado($clinica, 'Pipoca');
        $this->aplicar($vencida, $clinica, $antirrabica, now()->subMonths(14));

        $emVinteDias = $this->animalAutorizado($clinica, 'Mel');
        $this->aplicar($emVinteDias, $clinica, $antirrabica, now()->subMonths(12)->addDays(20));

        $atuando = $this->actingAs($marcelo);

        $atuando->getJson('/api/clinica/pendencias?dias=7')
            ->assertJsonPath('filtros.dias', 7)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.animal.nome', 'Pipoca');

        $atuando->getJson('/api/clinica/pendencias?dias=30')->assertJsonPath('total', 2);

        // Período fora da lista volta ao padrão, como o intervalo de V01.
        $atuando->getJson('/api/clinica/pendencias?dias=45')->assertJsonPath('filtros.dias', 30);
    }

    /**
     * RF49 — "indicação da última notificação enviada". É a coluna que decide o
     * telefonema, e por isso distingue três estados: avisado e confirmado,
     * avisado sem confirmação, e nunca avisado.
     */
    public function test_a_linha_traz_a_ultima_notificacao_enviada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $avisado = $this->animalAutorizado($clinica, 'Pipoca');
        $this->aplicar($avisado, $clinica, $antirrabica, now()->subMonths(14));

        Notificacao::factory()->create([
            'animal_id' => $avisado->id,
            'tutor_id' => $avisado->tutor_id,
            'imunobiologico_id' => $antirrabica->id,
            'tipo' => 'aviso_previo',
            'referente_a' => now()->subMonths(2)->toDateString(),
            'enviada_em' => now()->subMonths(3),
        ]);

        // A mais recente vence a anterior: a pergunta é "já foi avisado?", e a
        // resposta é a última vez, não a primeira.
        Notificacao::factory()->alertaDeAtraso()->semConfirmacao()->create([
            'animal_id' => $avisado->id,
            'tutor_id' => $avisado->tutor_id,
            'imunobiologico_id' => $antirrabica->id,
            'referente_a' => now()->subMonths(2)->toDateString(),
            'enviada_em' => now()->subDays(4),
        ]);

        $nuncaAvisado = $this->animalAutorizado($clinica, 'Théo');
        $this->aplicar($nuncaAvisado, $clinica, $antirrabica, now()->subMonths(15));

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/pendencias');

        $resposta->assertJsonPath('itens.0.animal.nome', 'Théo');
        $resposta->assertJsonPath('itens.0.ultima_notificacao', null);

        $resposta->assertJsonPath('itens.1.animal.nome', 'Pipoca');
        $resposta->assertJsonPath('itens.1.ultima_notificacao.situacao', 'sem_confirmacao');
        $resposta->assertJsonPath(
            'itens.1.ultima_notificacao.em',
            now()->subDays(4)->toDateString(),
        );
    }

    /**
     * A etiqueta de situação e a coluna de atraso contam os mesmos dias. Elas
     * saem de cálculos distintos — uma do calendário, outra desta consulta — e
     * chegaram a divergir: a data prevista carrega a hora da aplicação, e
     * compará-la com a meia-noite de hoje truncava um dia, de modo que a mesma
     * dose era "há 27 dias" na carteira e "28 dias" aqui.
     */
    public function test_a_situacao_e_a_coluna_de_atraso_contam_os_mesmos_dias(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $animal = $this->animalAutorizado($clinica, 'Pipoca');
        // A hora da aplicação é justamente o que produzia a divergência.
        $this->aplicar($animal, $clinica, $antirrabica, now()->subMonths(13)->setTime(9, 30));

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/pendencias');

        $item = $resposta->json('itens.0');

        $this->assertSame(
            "há {$item['atraso']['dias']} dias",
            $item['situacao']['texto_curto'],
            'A etiqueta de situação e a coluna de atraso divergiram na contagem de dias.',
        );
    }

    public function test_a_consulta_nao_mistura_prestadores(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Bicho Bom');
        $marcelo = $this->marcelo($clinica, $hospital);
        $antirrabica = $this->antirrabica();

        $daClinica = $this->animalAutorizado($clinica, 'Théo');
        $this->aplicar($daClinica, $clinica, $antirrabica, now()->subMonths(14));

        $doHospital = $this->animalAutorizado($hospital, 'Amora');
        $this->aplicar($doHospital, $hospital, $antirrabica, now()->subMonths(14));

        $this->actingAs($marcelo)->getJson('/api/clinica/pendencias')
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.animal.nome', 'Théo');

        $this->actingAs($marcelo)->getJson("/api/clinica/pendencias?prestador={$hospital->id}")
            ->assertJsonPath('prestador.nome', 'Hospital Bicho Bom')
            ->assertJsonPath('itens.0.animal.nome', 'Amora')
            ->assertJsonCount(2, 'vinculos');
    }

    public function test_prestador_sem_vinculo_com_o_profissional_e_recusado(): void
    {
        $clinica = $this->clinica();
        $alheio = $this->clinica('Clínica de Outrem');
        $marcelo = $this->marcelo($clinica);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/pendencias?prestador={$alheio->id}")
            ->assertForbidden();
    }

    /**
     * RF49b — o resultado é exportável para acompanhamento da rechamada, com os
     * mesmos filtros da tela: o arquivo precisa ser conferível contra ela.
     */
    public function test_a_exportacao_devolve_csv_com_os_filtros_aplicados(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $cao = $this->animalAutorizado($clinica, 'Pipoca', ['especie' => 'cao']);
        $this->aplicar($cao, $clinica, $antirrabica, now()->subMonths(14));

        $gato = $this->animalAutorizado($clinica, 'Mel', ['especie' => 'gato']);
        $this->aplicar($gato, $clinica, $antirrabica, now()->subMonths(14));

        $resposta = $this->actingAs($marcelo)->get('/api/clinica/pendencias/exportar?especie=gato');

        $resposta->assertOk();
        $resposta->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $resposta->streamedContent();

        $this->assertStringContainsString('Última notificação', $csv);
        $this->assertStringContainsString('Mel', $csv);
        $this->assertStringContainsString('nunca notificado', $csv);
        // O filtro de espécie valeu para o arquivo, e não só para a tela.
        $this->assertStringNotContainsString('Pipoca', $csv);
    }

    public function test_a_exportacao_tambem_exige_vinculo_de_veterinario(): void
    {
        $tutor = Tutor::factory()->create();

        $this->actingAs($tutor->user)
            ->get('/api/clinica/pendencias/exportar')
            ->assertForbidden();
    }
}
