<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use App\Models\VersaoProtocolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V07 — registrar vacinação (RF25, RF26, RF27).
 *
 * É a primeira escrita de registro clínico **profissional** do sistema, e a
 * suíte nasce inteira por isso. Quatro garantias são o assunto:
 *
 * 1. **A autoria não vem do formulário** (RF25b, RN21). Nome, CRMV e usuário
 *    saem da sessão; quem não tem CRMV no vínculo não escreve registro clínico.
 * 2. **Lote e validade são obrigatórios** (RF25a, RN22), e a validade expirada
 *    exige confirmação — mas não impede (RF25c).
 * 3. **O painel não mente** (RF26c). A data que a prévia promete ao veterinário
 *    é, caractere por caractere, a que a carteira do tutor mostra depois de
 *    gravado o registro.
 * 4. **Nenhum alerta impede o registro** (RF27c, RN36), e nenhum caminho altera
 *    ou apaga o que foi gravado (RN26).
 */
class RegistroDeVacinacaoTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function animalDe(string $nomeDoTutor, string $nome, array $atributos = []): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => $nomeDoTutor]);

        return Animal::factory()->caracterizado()->create([
            ...$atributos,
            'tutor_id' => $tutor->id,
            'nome' => $nome,
        ]);
    }

    private function autorizar(Animal $animal, Prestador $prestador, string $estado = 'vigente'): Autorizacao
    {
        $factory = Autorizacao::factory();

        if ($estado !== 'vigente') {
            $factory = $factory->{$estado}();
        }

        return $factory->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $animal->tutor->user_id,
        ]);
    }

    private function antirrabica(): Imunobiologico
    {
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create([
            'fabricante' => 'MSD',
            'via_administracao_usual' => 'Subcutânea',
        ]);

        ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        return $imunobiologico;
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    private function corpo(Imunobiologico $imunobiologico, array $sobrescritas = []): array
    {
        return [
            'imunobiologico' => $imunobiologico->chave,
            'fabricante' => 'MSD',
            'lote' => 'K90-2207',
            'validade' => '04/'.now()->addYear()->year,
            'via_administracao' => 'Subcutânea',
            'aplicado_em' => now()->toDateTimeString(),
            'ordem_dose' => 1,
            ...$sobrescritas,
        ];
    }

    /* Âmbito ---------------------------------------------------------------- */

    public function test_o_registro_exige_sessao(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")->assertUnauthorized();
        $this->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", [])->assertUnauthorized();
    }

    public function test_quem_nao_tem_vinculo_de_veterinario_nao_registra(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertForbidden();
    }

    public function test_administrador_do_prestador_nao_registra(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        // RN08 — o papel administrativo não alcança o dado clínico.
        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($prestador, ['papel' => 'admin_prestador']);

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertForbidden();
    }

    /**
     * RN21 — o teste central da fatia. O vínculo é de veterinário, o prestador é
     * o certo, a autorização está vigente: só falta a inscrição, e sem ela não
     * há registro clínico a criar.
     */
    public function test_veterinario_sem_crmv_no_vinculo_nao_registra(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($prestador, [
            'papel' => 'veterinario',
            'crmv' => null,
            'crmv_uf' => null,
        ]);

        $imunobiologico = $this->antirrabica();

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertForbidden();

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico))
            ->assertForbidden();

        $this->assertDatabaseCount('vacinacoes', 0);
    }

    public function test_vinculo_encerrado_nao_registra(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $usuario = $this->marcelo($prestador);
        $usuario->prestadores()->updateExistingPivot($prestador->id, ['encerrado_em' => now()->subDay()]);

        $this->actingAs($usuario->fresh())
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertForbidden();
    }

    public function test_prestador_sem_vinculo_no_parametro_e_recusado(): void
    {
        $prestador = $this->clinica();
        $outro = $this->clinica('Pet Center Zona Sul');
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $usuario = $this->marcelo($prestador);

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar?prestador={$outro->id}")
            ->assertForbidden();
    }

    public function test_codigo_inexistente_responde_404(): void
    {
        $prestador = $this->clinica();
        $usuario = $this->marcelo($prestador);

        $this->actingAs($usuario)
            ->getJson('/api/clinica/animais/IM-0000-0000/vacinar')
            ->assertNotFound();
    }

    public function test_animal_sem_autorizacao_vigente_nao_recebe_registro(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $resposta = $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico));

        $resposta->assertForbidden();

        // A recusa nomeia o caminho: o profissional pode pedir acesso (V10).
        $this->assertStringContainsString('Solicite o acesso', $resposta->json('message'));
        $this->assertDatabaseCount('vacinacoes', 0);
    }

    public function test_autorizacao_expirada_e_revogada_nao_registram(): void
    {
        $imunobiologico = $this->antirrabica();

        foreach (['expirada', 'revogada'] as $estado) {
            $prestador = $this->clinica();
            $animal = $this->animalDe('Helena Ramos', 'Théo');
            $this->autorizar($animal, $prestador, $estado);
            $usuario = $this->marcelo($prestador);

            $this->actingAs($usuario)
                ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('vacinacoes', 0);
    }

    /** RF22a — registrado o óbito, o calendário se encerra. */
    public function test_animal_com_obito_nao_recebe_registro(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo', ['obito_em' => now()->subMonth()->toDateString()]);
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico))
            ->assertForbidden();

        $this->assertDatabaseCount('vacinacoes', 0);
    }

    /* Autoria --------------------------------------------------------------- */

    /**
     * RF25b — a identificação do aplicador é preenchida a partir do usuário
     * autenticado e não é editável. "Não editável" tem de valer também para quem
     * não usa a tela: o corpo abaixo tenta assinar em nome de outra pessoa.
     */
    public function test_a_autoria_vem_da_sessao_e_ignora_o_corpo(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico, [
                'aplicador_nome' => 'Outra Pessoa',
                'aplicador_crmv' => 'CRMV-SP 99999',
                'aplicador_user_id' => 999,
                'origem' => 'pregresso',
                'data_aproximada' => true,
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('vacinacoes', [
            'animal_id' => $animal->id,
            'aplicador_nome' => 'Marcelo Andrade',
            'aplicador_crmv' => 'CRMV-MG 12345',
            'aplicador_user_id' => $usuario->id,
            'prestador_id' => $prestador->id,

            // RN25 — a origem e a imprecisão de data são do pregresso, e o corpo
            // não pode convertê-las: aqui houve ato clínico, com hora.
            'origem' => 'profissional',
            'data_aproximada' => false,
            'lancado_por_user_id' => null,
        ]);
    }

    /* Lote, validade e catálogo --------------------------------------------- */

    public function test_lote_e_validade_sao_obrigatorios(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico, [
                'lote' => '',
                'validade' => '',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lote', 'validade']);
    }

    public function test_validade_fora_do_formato_mes_ano_e_recusada(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        foreach (['13/2027', '2027', '04/27', '2027-04-30'] as $invalida) {
            $this->actingAs($usuario)
                ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico, [
                    'validade' => $invalida,
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('validade');
        }
    }

    /** RF25c — o alerta exige confirmação; não impede a aplicação. */
    public function test_validade_expirada_exige_confirmacao_e_fica_sinalizada(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $vencida = ['validade' => '01/'.now()->subYear()->year];

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico, $vencida))
            ->assertStatus(422)
            ->assertJsonValidationErrors('validade_expirada_confirmada');

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico, [
                ...$vencida,
                'validade_expirada_confirmada' => true,
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('vacinacoes', [
            'animal_id' => $animal->id,
            'validade_expirada_confirmada' => true,
        ]);
    }

    /**
     * O defeito que a fatia corrigiu de passagem. `validade` é data pura e
     * `aplicado_em` carrega a hora: sem comparar dia contra dia, a vacina
     * aplicada às 14h do seu último dia de validade sairia acusada de vencida —
     * e a marca de RF25c é permanente e visível ao tutor.
     */
    public function test_aplicacao_no_ultimo_dia_de_validade_nao_exige_confirmacao(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        // O último dia de um mês já passado, às 14h: a hora é o que fazia a
        // comparação tropeçar, e a data tem de ser passada para não esbarrar em
        // `before_or_equal:now`.
        $ultimoDia = now()->subMonth()->endOfMonth()->setTime(14, 0);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico, [
                'validade' => $ultimoDia->format('m/Y'),
                'aplicado_em' => $ultimoDia->toDateTimeString(),
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('vacinacoes', [
            'animal_id' => $animal->id,
            'validade_expirada_confirmada' => false,
        ]);
    }

    /**
     * A marca de RF25c não é aposta a pedido: ela descreve um fato sobre as
     * datas, e `FichaClinicaService` acende alerta clínico a partir dela.
     */
    public function test_confirmacao_enviada_a_toa_nao_marca_registro_valido(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico, [
                'validade_expirada_confirmada' => true,
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('vacinacoes', [
            'animal_id' => $animal->id,
            'validade_expirada_confirmada' => false,
        ]);
    }

    public function test_vacina_inativa_ou_de_outra_especie_e_recusada(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo', ['especie' => 'cao']);
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $inativa = Imunobiologico::factory()->create(['chave' => 'fora-de-linha', 'ativo' => false]);
        $deGato = Imunobiologico::factory()->create([
            'chave' => 'triplice-felina',
            'ativo' => true,
            'especie_destino' => 'gato',
        ]);

        foreach ([$inativa, $deGato] as $recusada) {
            $this->actingAs($usuario)
                ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($recusada))
                ->assertStatus(422)
                ->assertJsonValidationErrors('imunobiologico');
        }
    }

    /* Protocolo e cálculo ---------------------------------------------------- */

    /**
     * RN32 — o registro guarda a linha de parâmetros vigente ao aplicar, e a
     * publicação de uma versão nova depois não a troca.
     */
    public function test_grava_o_protocolo_vigente_e_publicacao_posterior_nao_o_altera(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $vigente = $imunobiologico->protocoloVigente();

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico))
            ->assertCreated();

        $vacinacao = Vacinacao::query()->where('animal_id', $animal->id)->firstOrFail();
        $this->assertSame($vigente->id, $vacinacao->protocolo_vacinal_id);

        // Publica uma versão nova e encerra a anterior.
        $vigente->versaoProtocolo->update(['situacao' => VersaoProtocolo::ENCERRADA]);
        $nova = VersaoProtocolo::factory()->create(['rotulo' => '2027.1']);
        ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
            'versao_protocolo_id' => $nova->id,
        ]);

        $this->assertSame($vigente->id, $vacinacao->fresh()->protocolo_vacinal_id);
    }

    /**
     * `Imunobiologico::protocoloVigente()` pode devolver nulo, e o registro não
     * pode depender disso: RF25 grava a aplicação; RF26 calcula quando há de
     * quê. Sem protocolo, o painel diz que não sabe (RF50) em vez de inventar.
     */
    public function test_vacina_sem_protocolo_vigente_grava_sem_calculo(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $semProtocolo = Imunobiologico::factory()->create(['ativo' => true, 'especie_destino' => 'ambas']);

        $previa = $this->actingAs($usuario)->getJson(
            "/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$semProtocolo->chave}"
        );

        $previa->assertOk();
        $previa->assertJsonPath('calculo', null);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($semProtocolo))
            ->assertCreated();

        $this->assertDatabaseHas('vacinacoes', [
            'animal_id' => $animal->id,
            'protocolo_vacinal_id' => null,
        ]);
    }

    /**
     * RF26c — o teste que sustenta a arquitetura da fatia. A prévia não tem
     * cálculo próprio: ela monta a coleção hipotética e chama o mesmo método da
     * carteira. Se um dia alguém escrever um segundo cálculo para a tela, é aqui
     * que a divergência aparece.
     */
    public function test_a_data_da_previa_e_a_mesma_que_a_carteira_mostra_depois(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $previa = $this->actingAs($usuario)->getJson(
            "/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$imunobiologico->chave}"
        );

        $previa->assertOk();
        $prometida = $previa->json('calculo.prevista_para');
        $this->assertNotNull($prometida);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico))
            ->assertCreated();

        $carteira = $this->actingAs($animal->tutor->user)
            ->getJson("/api/animais/{$animal->codigo}/carteira");

        $carteira->assertOk();
        $carteira->assertJsonPath('grupos.0.proxima_dose.prevista_para', $prometida);
    }

    /** RF27a — atraso acima do limite alerta, com a conduta do protocolo. */
    public function test_atraso_acima_do_limite_alerta_com_conduta_sugerida(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        // Reforço anual previsto para 42 dias atrás; o limite é de 30.
        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()->id,
            'aplicado_em' => now()->subYear()->subDays(42),
            'ordem_dose' => 1,
        ]);

        $previa = $this->actingAs($usuario)->getJson(
            "/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$imunobiologico->chave}"
        );

        $previa->assertOk();
        $previa->assertJsonPath('alertas.0.tipo', 'atraso');
        $previa->assertJsonPath('alertas.0.conduta_sugerida', 'prosseguir com dose única');
    }

    public function test_atraso_dentro_do_limite_nao_alerta(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()->id,
            'aplicado_em' => now()->subYear()->subDays(5),
            'ordem_dose' => 1,
        ]);

        $previa = $this->actingAs($usuario)->getJson(
            "/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$imunobiologico->chave}"
        );

        $previa->assertOk();
        $previa->assertJsonPath('alertas', []);
    }

    /**
     * RF27c e RN36 — a conduta divergente grava. O sistema calculou "reforço" e
     * o profissional registrou "1ª dose", reiniciando a série: o registro nasce,
     * a divergência fica auditável e a carteira passa a contar dali.
     */
    public function test_conduta_divergente_grava_e_fica_auditavel(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()->id,
            'aplicado_em' => now()->subYears(3),
            'ordem_dose' => 1,
        ]);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($imunobiologico, [
                'ordem_dose' => 1,
                'justificativa_conduta' => 'Histórico sem documentação: reiniciada a imunização.',
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('vacinacoes', [
            'animal_id' => $animal->id,
            'ordem_dose' => 1,
            'ordem_dose_sugerida' => 2,
            'justificativa_conduta' => 'Histórico sem documentação: reiniciada a imunização.',
        ]);
    }

    /** RN33 — a dose final antes da idade mínima agenda uma dose adicional. */
    public function test_dose_final_antes_da_idade_minima_preve_dose_adicional(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo', [
            'nascimento_em' => now()->subWeeks(11)->toDateString(),
            'nascimento_exato' => true,
        ]);
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        // Série primária de 3 doses com idade mínima de 16 semanas na final.
        $imunobiologico = Imunobiologico::factory()->create(['ativo' => true, 'especie_destino' => 'ambas']);
        ProtocoloVacinal::factory()->create(['imunobiologico_id' => $imunobiologico->id]);

        $previa = $this->actingAs($usuario)->getJson(
            "/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$imunobiologico->chave}&ordem_dose=2"
        );

        $previa->assertOk();
        $previa->assertJsonPath('alertas.0.tipo', 'dose_adicional');
    }

    /* Livro de acessos ------------------------------------------------------- */

    /** RN49 — abrir a tela sobre registro alheio grava exatamente uma linha. */
    public function test_abrir_a_tela_sobre_registro_de_outro_prestador_grava_uma_linha(): void
    {
        $prestador = $this->clinica();
        $outro = $this->clinica('Pet Center Zona Sul');
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $outro->id,
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertOk();

        $this->assertSame(1, RegistroDeAcesso::query()
            ->where('animal_id', $animal->id)
            ->where('natureza', RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR)
            ->count());
    }

    public function test_registro_proprio_ou_pregresso_nao_grava_linha_de_acesso(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        // RN24 — o pregresso não vem de prestador algum. Registrá-lo como acesso
        // a dado de terceiro diria ao tutor que uma clínica leu o que ele mesmo
        // escreveu sobre o próprio animal.
        Vacinacao::factory()->pregresso()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertOk();

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /**
     * A razão de `previa` ser rota própria. Uma vacinação de noventa segundos
     * consulta o cálculo a cada campo alterado; se cada consulta gravasse, o
     * livro que RF53 promete legível ao tutor viraria ruído.
     */
    public function test_dez_previas_nao_gravam_linha_alguma_de_acesso(): void
    {
        $prestador = $this->clinica();
        $outro = $this->clinica('Pet Center Zona Sul');
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $outro->id,
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        foreach (range(1, 10) as $ignorado) {
            $this->actingAs($usuario)
                ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$imunobiologico->chave}")
                ->assertOk();
        }

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /* Imutabilidade ---------------------------------------------------------- */

    /** RN26 — não há caminho que altere ou apague registro clínico. */
    public function test_nao_existe_rota_de_alteracao_nem_de_exclusao(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        foreach (['putJson', 'patchJson', 'deleteJson'] as $verbo) {
            $this->actingAs($usuario)
                ->{$verbo}("/api/clinica/animais/{$animal->codigo}/vacinar", [])
                ->assertStatus(405);
        }
    }

    /**
     * Com o registro imutável e sem V09, um reenvio da rede criaria duas
     * aplicações permanentes da mesma dose — num prontuário, dois fatos clínicos
     * onde houve um.
     */
    public function test_pedido_repetido_nao_cria_segundo_registro(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $corpo = $this->corpo($imunobiologico);

        $primeira = $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $corpo)
            ->assertCreated();

        $segunda = $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $corpo)
            ->assertCreated();

        $this->assertSame($primeira->json('id'), $segunda->json('id'));
        $this->assertDatabaseCount('vacinacoes', 1);
    }

    /* Abertura da tela ------------------------------------------------------- */

    public function test_a_tela_traz_catalogo_da_especie_aplicador_e_contexto(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo', ['especie' => 'cao']);
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $this->antirrabica();
        Imunobiologico::factory()->create([
            'chave' => 'triplice-felina',
            'ativo' => true,
            'especie_destino' => 'gato',
        ]);

        $resposta = $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar");

        $resposta->assertOk();
        $resposta->assertJsonPath('prestador.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('animal.nome', 'Théo');
        $resposta->assertJsonPath('aplicador.nome', 'Marcelo Andrade');
        $resposta->assertJsonPath('aplicador.crmv', 'CRMV-MG 12345');

        // A vacina de gato não entra no catálogo de um cão (RN30).
        $resposta->assertJsonCount(1, 'catalogo');
    }

    /**
     * RNF15 — o que faz o registro caber em noventa segundos: os valores do
     * último frasco do mesmo imunobiológico **nesta clínica**.
     */
    public function test_sugestoes_vem_do_ultimo_registro_do_mesmo_prestador(): void
    {
        $prestador = $this->clinica();
        $outro = $this->clinica('Pet Center Zona Sul');
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);
        $imunobiologico = $this->antirrabica();

        $protocolo = $imunobiologico->protocoloVigente();

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'lote' => 'K90-2207',
            'fabricante' => 'MSD',
            'aplicado_em' => now()->subDays(5),
        ]);

        // O frasco da outra clínica nunca esteve nas mãos de quem aplica aqui.
        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $outro->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'lote' => 'NAO-SUGERIR',
            'aplicado_em' => now()->subDay(),
        ]);

        $previa = $this->actingAs($usuario)->getJson(
            "/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$imunobiologico->chave}"
        );

        $previa->assertOk();
        $previa->assertJsonPath('sugestoes.lote', 'K90-2207');
        $previa->assertJsonPath('sugestoes.fabricante', 'MSD');
    }

    /* Acervo próprio da clínica (A04) --------------------------------------- */

    /**
     * Uma vacina do acervo próprio de um prestador, com agendamento.
     *
     * @param  array<string, mixed>  $atributos
     * @param  array<string, mixed>  $parametros
     */
    private function vacinaDaClinica(Prestador $prestador, array $atributos = [], array $parametros = []): Imunobiologico
    {
        $imunobiologico = Imunobiologico::factory()->create([
            'chave' => 'p'.$prestador->id.'-'.fake()->unique()->slug(2),
            'nome_comercial' => 'Vacina da casa',
            'especie_destino' => 'cao',
            ...$atributos,
        ]);

        $imunobiologico->forceFill(['prestador_id' => $prestador->id])->save();

        ProtocoloVacinal::factory()->create([
            'imunobiologico_id' => $imunobiologico->id,
            'versao_protocolo_id' => null,
            ...$parametros,
        ]);

        return $imunobiologico;
    }

    public function test_catalogo_de_v07_traz_a_vacina_propria_da_clinica(): void
    {
        $clinica = $this->clinica();
        $usuario = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        $propria = $this->vacinaDaClinica($clinica);

        $resposta = $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertOk();

        $chaves = collect($resposta->json('catalogo'))->pluck('chave');

        $this->assertTrue($chaves->contains($propria->chave));

        // A lista mistura dois acervos, e a origem muda o peso do que se lê: é o
        // veterinário quem responde pelo ato (RN36).
        $item = collect($resposta->json('catalogo'))->firstWhere('chave', $propria->chave);
        $this->assertTrue($item['propria']);
    }

    public function test_catalogo_de_v07_nao_traz_vacina_privada_de_outra_clinica(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica('Hospital Bicho Bom');
        $usuario = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        $alheia = $this->vacinaDaClinica($outra);

        $resposta = $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertOk();

        $this->assertFalse(collect($resposta->json('catalogo'))->pluck('chave')->contains($alheia->chave));
    }

    public function test_catalogo_de_v07_nao_traz_vacina_propria_inativada(): void
    {
        $clinica = $this->clinica();
        $usuario = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        // Sem o grupo aninhado no escopo, a precedência de AND sobre OR soltaria
        // este ramo e o item inativo voltaria à lista.
        $inativa = $this->vacinaDaClinica($clinica, ['ativo' => false]);

        $resposta = $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertOk();

        $this->assertFalse(collect($resposta->json('catalogo'))->pluck('chave')->contains($inativa->chave));
    }

    public function test_catalogo_de_v07_nao_traz_vacina_propria_de_outra_especie(): void
    {
        $clinica = $this->clinica();
        $usuario = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo', ['especie' => 'cao']);
        $this->autorizar($animal, $clinica);

        $felina = $this->vacinaDaClinica($clinica, ['especie_destino' => 'gato']);

        $resposta = $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar")
            ->assertOk();

        $this->assertFalse(collect($resposta->json('catalogo'))->pluck('chave')->contains($felina->chave));
    }

    public function test_registro_com_chave_de_outra_clinica_e_recusado_com_mensagem_propria(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica('Hospital Bicho Bom');
        $usuario = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        $alheia = $this->vacinaDaClinica($outra, ['especie_destino' => 'cao']);

        // A espécie está certa; o que falha é o acervo. Dizer "não é aplicada
        // nesta espécie" mandaria conferir o que não tem problema algum.
        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($alheia))
            ->assertJsonValidationErrors('imunobiologico')
            ->assertJsonPath(
                'errors.imunobiologico.0',
                'Esta vacina não está no catálogo desta clínica. Escolha uma da lista.',
            );
    }

    public function test_vacina_de_dose_unica_nao_oferece_ordem_de_reforco(): void
    {
        $clinica = $this->clinica();
        $usuario = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        $propria = $this->vacinaDaClinica($clinica, [], [
            'numero_doses_serie_primaria' => 1,
            'intervalo_minimo_dias' => 0,
            'intervalo_maximo_dias' => 0,
            'idade_minima_dose_final_semanas' => null,
            'reforco_inicial_meses' => null,
            'periodicidade_revacinacao_meses' => null,
        ]);

        // Sem esta guarda, o rótulo da ordem seguinte sairia de uma
        // periodicidade nula e a tela responderia 500.
        $resposta = $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$propria->chave}")
            ->assertOk();

        $this->assertCount(1, $resposta->json('ordem_dose.opcoes'));
        $this->assertSame(1, $resposta->json('ordem_dose.opcoes.0.valor'));
        $this->assertSame('1ª dose', $resposta->json('ordem_dose.opcoes.0.rotulo'));
    }

    public function test_painel_anuncia_que_nao_havera_proxima_dose(): void
    {
        $clinica = $this->clinica();
        $usuario = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        $propria = $this->vacinaDaClinica($clinica, [], [
            'numero_doses_serie_primaria' => 1,
            'intervalo_minimo_dias' => 0,
            'intervalo_maximo_dias' => 0,
            'idade_minima_dose_final_semanas' => null,
            'reforco_inicial_meses' => null,
            'periodicidade_revacinacao_meses' => null,
        ]);

        // Painel vazio no meio do registro lê-se como falha. Há resposta, e ela
        // é que não haverá outra dose.
        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/vacinar/previa?imunobiologico={$propria->chave}")
            ->assertOk()
            ->assertJsonPath('calculo.rotulo', 'Série concluída')
            ->assertJsonPath('calculo.prevista_para', null)
            ->assertJsonPath('calculo.protocolo_versao', 'próprio da clínica');
    }

    public function test_aplicacao_de_vacina_propria_congela_o_agendamento_da_epoca(): void
    {
        $clinica = $this->clinica();
        $usuario = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        $propria = $this->vacinaDaClinica($clinica);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/vacinar", $this->corpo($propria))
            ->assertCreated();

        $vacinacao = Vacinacao::where('imunobiologico_id', $propria->id)->firstOrFail();

        // A linha congelada é a sem versão — a que o agendamento da clínica
        // escreveu, e que a publicação seguinte da plataforma não alcança.
        $this->assertSame($propria->protocoloVigente()->id, $vacinacao->protocolo_vacinal_id);
        $this->assertNull($vacinacao->protocoloVacinal->versao_protocolo_id);
    }
}
