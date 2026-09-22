<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T09 — lançamento de histórico pregresso não verificado (RF29, RN24, RN25).
 */
class HistoricoPregressoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function helena(): Tutor
    {
        $usuario = User::factory()->create([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
        ]);

        return Tutor::factory()->create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
        ]);
    }

    private function theo(Tutor $tutor): Animal
    {
        return Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    private function payload(array $sobrescritas = []): array
    {
        return [
            'imunobiologico' => 'antirrabica',
            'data' => '2024',
            'local_aplicacao' => 'Campanha pública de vacinação',
            'fabricante' => null,
            'lote' => null,
            'ciente_nao_verificado' => true,
            ...$sobrescritas,
        ];
    }

    // Guardas de âmbito — as mesmas de T02, T04 e T05. --------------------

    public function test_o_formulario_exige_sessao(): void
    {
        $this->getJson('/api/animais/IM-7F3K-92QD/pregresso')->assertUnauthorized();
    }

    public function test_o_lancamento_exige_sessao(): void
    {
        $this->postJson('/api/animais/IM-7F3K-92QD/pregresso', $this->payload())->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_lanca_pregresso(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/animais/IM-7F3K-92QD/pregresso', $this->payload())
            ->assertForbidden();
    }

    // RN12 — mesma resposta para código inexistente e animal de outro tutor.
    public function test_animal_de_outro_tutor_responde_404_no_lancamento(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $this->actingAs($helena->user)
            ->postJson("/api/animais/{$animal->codigo}/pregresso", $this->payload())
            ->assertNotFound();
    }

    /**
     * A ordem das respostas importa: um corpo inválido para o animal de outro
     * tutor precisa responder 404, e não 422 — senão o formato do erro já
     * contaria que o pedido chegou a ser examinado.
     */
    public function test_animal_de_outro_tutor_responde_404_mesmo_com_corpo_invalido(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $this->actingAs($helena->user)
            ->postJson("/api/animais/{$animal->codigo}/pregresso", [])
            ->assertNotFound();
    }

    // O formulário — catálogo e autoria. ----------------------------------

    public function test_o_formulario_oferece_o_catalogo_da_especie_do_animal(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);

        Imunobiologico::factory()->create(['chave' => 'v10-multipla-canina', 'especie_destino' => 'cao']);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);
        Imunobiologico::factory()->create([
            'chave' => 'quintupla-felina',
            'nome_comercial' => 'V5 múltipla felina',
            'especie_destino' => 'gato',
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$theo->codigo}/pregresso");

        $resposta->assertOk();
        // "ambas" cobre a antirrábica; a vacina de gato não é oferecida ao cão.
        $this->assertEqualsCanonicalizing(
            ['antirrabica', 'v10-multipla-canina'],
            array_column($resposta->json('imunobiologicos'), 'chave'),
        );
    }

    public function test_o_formulario_omite_imunobiologico_inativo(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);

        Imunobiologico::factory()->create(['chave' => 'descontinuada', 'ativo' => false]);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$theo->codigo}/pregresso");

        $resposta->assertOk();
        $resposta->assertJsonPath('imunobiologicos', []);
    }

    /**
     * RF29b — a pré-visualização mostra quem vai constar como autor, no mesmo
     * formato que a carteira usa nos registros já gravados.
     */
    public function test_o_formulario_anuncia_quem_lancara_o_registro(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$theo->codigo}/pregresso");

        $resposta->assertOk();
        $resposta->assertJsonPath('lancado_por.nome', 'Helena Ramos');
        $resposta->assertJsonPath('lancado_por.em', now()->toDateString());
        $resposta->assertJsonPath('animal.nome', 'Théo');
    }

    // O lançamento. -------------------------------------------------------

    public function test_lanca_o_registro_marcado_como_nao_verificado(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $resposta = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload());

        $resposta->assertCreated();

        $registro = Vacinacao::findOrFail($resposta->json('vacinacao.id'));

        // RN24 — a marca nasce com o registro.
        $this->assertSame('pregresso', $registro->origem);
        $this->assertTrue($registro->pregresso());

        // RN25 — não é ato clínico: ninguém aplicou, ninguém assinou.
        $this->assertNull($registro->prestador_id);
        $this->assertNull($registro->protocolo_vacinal_id);
        $this->assertNull($registro->aplicador_nome);
        $this->assertNull($registro->aplicador_crmv);

        // RF29b — quem lançou e quando.
        $this->assertSame($tutor->user->id, $registro->lancado_por_user_id);
        $this->assertSame(now()->toDateString(), $registro->created_at->toDateString());

        $this->assertSame('Campanha pública de vacinação', $registro->local_aplicacao);
        $this->assertSame($theo->id, $registro->animal_id);
    }

    /**
     * RN25 — o ano informado vira o início do período, e a marca de data
     * aproximada é o que preserva a imprecisão do relato.
     */
    public function test_o_ano_informado_vira_data_aproximada(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $resposta = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload(['data' => '2024']));

        $registro = Vacinacao::findOrFail($resposta->json('vacinacao.id'));

        $this->assertSame('2024-01-01', $registro->aplicado_em->toDateString());
        $this->assertTrue($registro->data_aproximada);
    }

    public function test_o_mes_e_o_ano_informados_viram_o_primeiro_dia_do_mes(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $resposta = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload(['data' => '05/2024']));

        $registro = Vacinacao::findOrFail($resposta->json('vacinacao.id'));

        $this->assertSame('2024-05-01', $registro->aplicado_em->toDateString());
        $this->assertTrue($registro->data_aproximada);
    }

    /**
     * RF29 — "com os dados de que se disponha": o tutor lembra que houve uma
     * aplicação, mas não qual vacina foi.
     */
    public function test_lanca_registro_sem_identificar_a_vacina(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);

        $resposta = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload(['imunobiologico' => null]));

        $resposta->assertCreated();

        $registro = Vacinacao::findOrFail($resposta->json('vacinacao.id'));
        $this->assertNull($registro->imunobiologico_id);
    }

    public function test_lanca_registro_sem_data(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $resposta = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload(['data' => null]));

        $resposta->assertCreated();

        $registro = Vacinacao::findOrFail($resposta->json('vacinacao.id'));
        $this->assertNull($registro->aplicado_em);
        $this->assertFalse($registro->data_aproximada);
    }

    // Validação. ----------------------------------------------------------

    /**
     * Sem vacina e sem data o registro não afirma fato algum — é a única
     * exigência de conteúdo que sobra em RF29.
     */
    public function test_registro_sem_vacina_e_sem_data_e_recusado(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload([
                'imunobiologico' => null,
                'data' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['imunobiologico', 'data']);
    }

    public function test_campo_em_branco_conta_como_ausente(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload([
                'imunobiologico' => '',
                'data' => '   ',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['imunobiologico', 'data']);
    }

    public function test_data_fora_do_formato_aproximado_e_recusada(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload(['data' => '12/06/2024']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data');
    }

    public function test_data_futura_e_recusada(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $anoQueVem = now()->addYear()->format('Y');

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload(['data' => $anoQueVem]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('data');
    }

    /**
     * RF29a — a tela é obrigada a explicar a permanência da marca antes de
     * salvar, e o servidor não depende de ela ter cumprido a sua parte.
     */
    public function test_lancamento_sem_confirmacao_explicita_e_recusado(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload([
                'ciente_nao_verificado' => false,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ciente_nao_verificado');
    }

    public function test_vacina_fora_do_catalogo_e_recusada(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload([
                'imunobiologico' => 'vacina-que-nao-existe',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('imunobiologico');
    }

    /**
     * O catálogo que a tela recebeu já vem filtrado por espécie; esta é a
     * mesma regra do lado que não depende de a tela ter obedecido.
     */
    public function test_vacina_de_outra_especie_e_recusada(): void
    {
        $tutor = $this->helena();
        $nina = Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);

        Imunobiologico::factory()->create(['chave' => 'v10-multipla-canina', 'especie_destino' => 'cao']);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$nina->codigo}/pregresso", $this->payload([
                'imunobiologico' => 'v10-multipla-canina',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('imunobiologico');
    }

    // Efeito na carteira (T05). -------------------------------------------

    /**
     * RF29c — a distinção é mantida em todas as telas: o registro recém
     * lançado aparece na carteira como não verificado, com o nome de quem o
     * lançou, e não recebe rótulo ordinal de dose.
     */
    public function test_o_registro_lancado_aparece_na_carteira_como_nao_verificado(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload())
            ->assertCreated();

        $carteira = $this->actingAs($tutor->user)->getJson("/api/animais/{$theo->codigo}/carteira");

        $carteira->assertOk();
        $carteira->assertJsonPath('resumo.nao_verificadas', 1);
        $carteira->assertJsonPath('grupos.0.aplicacoes.0.origem', 'pregresso');
        $carteira->assertJsonPath('grupos.0.aplicacoes.0.rotulo', 'Aplicação anterior');
        $carteira->assertJsonPath('grupos.0.aplicacoes.0.lancado_por.nome', 'Helena Ramos');
        $carteira->assertJsonPath('grupos.0.aplicacoes.0.local_aplicacao', 'Campanha pública de vacinação');
    }

    /**
     * O registro sem vacina identificada forma um grupo próprio, que se
     * anuncia pelo que é — e não se mistura a imunobiológico algum.
     */
    public function test_registro_sem_vacina_forma_grupo_proprio_na_carteira(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        $antirrabica = Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        Vacinacao::factory()->pregresso()->create([
            'animal_id' => $theo->id,
            'imunobiologico_id' => $antirrabica->id,
            'aplicado_em' => '2023-01-01',
            'lancado_por_user_id' => $tutor->user->id,
        ]);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload(['imunobiologico' => null]))
            ->assertCreated();

        $carteira = $this->actingAs($tutor->user)->getJson("/api/animais/{$theo->codigo}/carteira");

        $carteira->assertOk();
        $carteira->assertJsonCount(2, 'grupos');

        $semVacina = collect($carteira->json('grupos'))
            ->firstWhere('imunobiologico.chave', 'sem-imunobiologico');

        $this->assertNotNull($semVacina);
        $this->assertSame('Vacina não identificada', $semVacina['imunobiologico']['nome']);
        $this->assertNull($semVacina['imunobiologico']['classificacao']);
        $this->assertNull($semVacina['proxima_dose']);
        $this->assertSame('nao-verificada', $semVacina['situacao']['tipo']);
    }

    /**
     * Sem data, o calendário não calcula nada (RF26) — e a dose não entra no
     * trilho, porque o trilho é uma linha do tempo e ela não tem lugar nele.
     */
    public function test_registro_sem_data_nao_alimenta_o_calculo_nem_o_trilho(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);
        Imunobiologico::factory()->antirrabica()->create(['chave' => 'antirrabica']);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload(['data' => null]))
            ->assertCreated();

        $carteira = $this->actingAs($tutor->user)->getJson("/api/animais/{$theo->codigo}/carteira");

        $carteira->assertOk();
        $carteira->assertJsonPath('grupos.0.proxima_dose', null);
        $carteira->assertJsonPath('grupos.0.estacoes', []);
        $carteira->assertJsonPath('grupos.0.aplicacoes.0.data', null);
        $carteira->assertJsonPath('proximas_doses', []);
    }

    /**
     * T06 e T07 leem o mesmo registro: nenhuma das duas telas pode quebrar
     * diante de uma aplicação sem vacina e sem data.
     */
    public function test_o_detalhe_e_o_historico_leem_o_registro_sem_vacina_e_sem_data(): void
    {
        $tutor = $this->helena();
        $theo = $this->theo($tutor);

        $resposta = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$theo->codigo}/pregresso", $this->payload([
                'imunobiologico' => null,
                'data' => '2019',
            ]));

        $id = $resposta->json('vacinacao.id');

        $detalhe = $this->actingAs($tutor->user)->getJson("/api/animais/{$theo->codigo}/vacinas/{$id}");
        $detalhe->assertOk();
        $detalhe->assertJsonPath('imunobiologico.nome', 'Vacina não identificada');
        $detalhe->assertJsonPath('aplicacao.hora', null);
        $detalhe->assertJsonPath('aplicacao.lancado_por.nome', 'Helena Ramos');

        $historico = $this->actingAs($tutor->user)->getJson("/api/animais/{$theo->codigo}/historico");
        $historico->assertOk();
        $historico->assertJsonPath('entradas.0.titulo', 'Vacina não identificada');
        $historico->assertJsonPath('entradas.0.prestador.rotulo', 'Lançado pelo tutor');
    }
}
