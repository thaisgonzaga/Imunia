<?php

namespace Tests\Feature;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * V08 — registrar atendimento (RF31, RF32, RF34).
 *
 * A segunda escrita de registro clínico profissional do sistema, e a primeira
 * que produz **prontuário**. Quatro garantias são o assunto da suíte:
 *
 * 1. **Quem escreve é quem tem inscrição** (RN21), no prestador que escolheu
 *    (RF31c), sobre animal cujo tutor autorizou (RN37, RN48).
 * 2. **Data, hora e autoria são do sistema** (RF31b): o que o formulário
 *    mandar sobre esses três é ignorado.
 * 3. **O anexo só existe vinculado ao registro** (RN28), com formato e tamanho
 *    conferidos no envio, e nunca alcançável por endereço direto (RF32c).
 * 4. **Nada altera o que foi gravado** (RN26): não há rota, e um reenvio da
 *    rede não vira segundo prontuário.
 */
class RegistroDeAtendimentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O disco dos anexos é privado (RF32c). Falsificá-lo mantém os testes
        // sem tocar no armazenamento real e permite conferir o que foi gravado.
        Storage::fake(AnexoAtendimento::DISCO);
    }

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

        return Animal::factory()->create([
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

    /**
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    private function corpo(array $sobrescritas = []): array
    {
        return [
            'motivo' => 'Prurido intenso e alopecia focal em região dorsal, com duas semanas de evolução.',
            'anamnese' => 'Início após passeios em área de vegetação alta. Sem alteração alimentar.',
            'exame_fisico' => 'TR 38,6 °C. Eritema e descamação em região dorsal, sem odor.',
            'hipoteses_diagnosticas' => 'DAPE; dermatite por ácaros.',
            'diagnostico' => 'Aguardando resultado do raspado cutâneo coletado nesta consulta.',
            'conduta' => 'Xampu tópico duas vezes por semana. Reavaliação em três dias.',
            ...$sobrescritas,
        ];
    }

    /* Âmbito ---------------------------------------------------------------- */

    public function test_o_registro_exige_sessao(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->getJson("/api/clinica/animais/{$animal->codigo}/atender")->assertUnauthorized();
        $this->postJson("/api/clinica/animais/{$animal->codigo}/atender", [])->assertUnauthorized();
    }

    public function test_quem_nao_tem_vinculo_de_veterinario_nao_registra(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->actingAs(User::factory()->create())
            ->getJson("/api/clinica/animais/{$animal->codigo}/atender")
            ->assertForbidden();
    }

    /** RN08 — o papel administrativo não alcança o dado clínico. */
    public function test_administrador_do_prestador_nao_registra(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($prestador, ['papel' => 'admin_prestador']);

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/atender")
            ->assertForbidden();
    }

    /** RN21 — vínculo, prestador e autorização em ordem; falta a inscrição. */
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

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/atender")
            ->assertForbidden();

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/atender", $this->corpo())
            ->assertForbidden();

        $this->assertDatabaseCount('atendimentos', 0);
    }

    /** RN37, RN48 — sem autorização vigente não há prontuário a escrever. */
    public function test_sem_autorizacao_vigente_o_registro_e_recusado(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador, 'revogada');
        $usuario = $this->marcelo($prestador);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/atender", $this->corpo())
            ->assertForbidden()
            ->assertJsonPath('message', 'Este animal não está sob autorização vigente do tutor. Solicite o acesso antes de registrar.');

        $this->assertDatabaseCount('atendimentos', 0);
    }

    /** RF22a — registrado o óbito, encerra-se o registro clínico do animal. */
    public function test_animal_com_obito_nao_recebe_atendimento(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo', ['obito_em' => now()->subMonth()]);
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/atender", $this->corpo())
            ->assertForbidden();

        $this->assertDatabaseCount('atendimentos', 0);
    }

    /** RN12 — código inexistente é 404; fora do âmbito é 403. São coisas diferentes. */
    public function test_codigo_inexistente_responde_404(): void
    {
        $prestador = $this->clinica();
        $usuario = $this->marcelo($prestador);

        $this->actingAs($usuario)
            ->getJson('/api/clinica/animais/IM-0000-0000/atender')
            ->assertNotFound();
    }

    /* Gravação -------------------------------------------------------------- */

    /**
     * RF31 — o registro com o que a Resolução CFMV manda constar. RF31b e RF31c
     * são o que o teste vigia: data, hora, autoria e prestador saem da sessão,
     * e o que o formulário mandar a respeito deles é ignorado.
     */
    public function test_grava_o_prontuario_com_autoria_e_prestador_do_sistema(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $resposta = $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender",
            $this->corpo([
                // Tentativas de ditar ao sistema o que é dele.
                'atendido_em' => '2001-01-01 08:00:00',
                'profissional_nome' => 'Outra Pessoa',
                'profissional_crmv' => 'CRMV-SP 99999',
                'prestador_id' => 999,
            ]),
        );

        $resposta->assertCreated();

        $atendimento = Atendimento::query()->firstOrFail();

        $this->assertSame($animal->id, $atendimento->animal_id);
        $this->assertSame($prestador->id, $atendimento->prestador_id);
        $this->assertSame($usuario->id, $atendimento->profissional_user_id);
        $this->assertSame('Marcelo Andrade', $atendimento->profissional_nome);
        $this->assertSame('CRMV-MG 12345', $atendimento->profissional_crmv);
        $this->assertTrue($atendimento->atendido_em->isToday());
        $this->assertNull($atendimento->retifica_atendimento_id);

        // O título não é campo do formulário: sai da primeira oração do motivo,
        // sem a pontuação final.
        $this->assertSame(
            'Prurido intenso e alopecia focal em região dorsal, com duas semanas de evolução',
            $atendimento->titulo,
        );
    }

    /**
     * O motivo de três frases não vira título de três frases: a linha do tempo
     * (RF35) e o cabeçalho de T08 nomeiam o atendimento em uma linha.
     */
    public function test_o_titulo_sai_da_primeira_oracao_do_motivo(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender",
            $this->corpo(['motivo' => 'Vômito há dois dias. Tutora relata ingestão de brinquedo de borracha.']),
        )->assertCreated();

        $this->assertSame('Vômito há dois dias', Atendimento::query()->firstOrFail()->titulo);
    }

    public function test_campos_do_prontuario_sao_obrigatorios_menos_o_diagnostico(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/atender", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['motivo', 'anamnese', 'exame_fisico', 'hipoteses_diagnosticas', 'conduta'])
            ->assertJsonMissingValidationErrors(['diagnostico']);

        // A consulta pode encerrar-se com o diagnóstico pendente de exame, e
        // afirmar um que não existe seria pior do que declarar a pendência.
        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/atender", $this->corpo(['diagnostico' => '']))
            ->assertCreated();

        $this->assertNull(Atendimento::query()->firstOrFail()->diagnostico);
    }

    /**
     * O peso é medição datada, e não campo do cadastro do animal: cada consulta
     * acrescenta um ponto à série, sem apagar o anterior.
     */
    public function test_peso_e_medicao_datada_e_a_anterior_permanece(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $anterior = Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'profissional_user_id' => $usuario->id,
            'atendido_em' => now()->subYear(),
            'peso_kg' => 11.9,
        ]);

        $tela = $this->actingAs($usuario)->getJson("/api/clinica/animais/{$animal->codigo}/atender");

        $tela->assertOk();
        $tela->assertJsonPath('peso_anterior.valor', '11.90');
        $tela->assertJsonPath('peso_anterior.em', $anterior->atendido_em->toDateString());

        // A balança escreve com vírgula, e o campo guarda o que foi digitado.
        $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/atender", $this->corpo(['peso_kg' => '12,4']))
            ->assertCreated();

        $this->assertSame('12.40', Atendimento::query()->latest('id')->firstOrFail()->peso_kg);
        $this->assertSame('11.90', $anterior->refresh()->peso_kg);
    }

    /** RF34 — data prevista **e** finalidade descrita, ou nenhuma das duas. */
    public function test_retorno_exige_data_e_finalidade_juntas(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $this->actingAs($usuario)
            ->postJson(
                "/api/clinica/animais/{$animal->codigo}/atender",
                $this->corpo(['retorno_em' => now()->addDays(3)->toDateString()]),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['retorno_finalidade']);

        $this->actingAs($usuario)
            ->postJson(
                "/api/clinica/animais/{$animal->codigo}/atender",
                $this->corpo([
                    'retorno_em' => now()->subDay()->toDateString(),
                    'retorno_finalidade' => 'Reavaliação',
                ]),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors(['retorno_em']);

        $this->actingAs($usuario)
            ->postJson(
                "/api/clinica/animais/{$animal->codigo}/atender",
                $this->corpo([
                    'retorno_em' => now()->addDays(3)->toDateString(),
                    'retorno_finalidade' => 'Reavaliação com resultado do raspado',
                ]),
            )
            ->assertCreated()
            ->assertJsonPath('retorno.finalidade', 'Reavaliação com resultado do raspado');

        $this->assertSame(
            now()->addDays(3)->toDateString(),
            Atendimento::query()->firstOrFail()->retorno_em->toDateString(),
        );
    }

    /**
     * RN29 — o retorno em aberto some quando um atendimento posterior à data
     * prevista é registrado, sem que ninguém precise marcá-lo como cumprido. A
     * tela precisa saber disso antes de confirmar, porque é consequência que
     * acontece fora dela: no painel de pendências e no lembrete ao tutor.
     */
    public function test_a_tela_anuncia_o_retorno_que_este_registro_encerra(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'profissional_user_id' => $usuario->id,
            'atendido_em' => now()->subDays(10),
            'retorno_em' => now()->subDays(2)->toDateString(),
            'retorno_finalidade' => 'Reavaliação com o resultado do raspado',
        ]);

        $tela = $this->actingAs($usuario)->getJson("/api/clinica/animais/{$animal->codigo}/atender");

        $tela->assertOk();
        $tela->assertJsonPath('retorno_em_aberto.finalidade', 'Reavaliação com o resultado do raspado');
        $tela->assertJsonPath('retorno_em_aberto.encerrado_por_este', true);
    }

    /* Anexos ---------------------------------------------------------------- */

    /** RN28 — formato conferido no envio, com a recusa nomeando o arquivo. */
    public function test_anexo_de_formato_recusado_nao_sobe(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $resposta = $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender/anexos",
            ['arquivo' => UploadedFile::fake()->create('exame-completo.docx', 40, 'application/msword')],
        );

        $resposta->assertStatus(422);
        $this->assertStringContainsString(
            'exame-completo.docx não foi anexado',
            $resposta->json('errors.arquivo.0'),
        );

        $this->assertEmpty(Storage::disk(AnexoAtendimento::DISCO)->allFiles());
    }

    public function test_anexo_acima_do_limite_e_recusado(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $resposta = $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender/anexos",
            ['arquivo' => UploadedFile::fake()->create('laudo.pdf', 11 * 1024, 'application/pdf')],
        );

        $resposta->assertStatus(422);
        $this->assertStringContainsString('10 MB por arquivo', $resposta->json('errors.arquivo.0'));
    }

    /**
     * RF32 — o anexo aceito espera pela confirmação e só então vira registro,
     * com descrição e data do exame. Antes disso não há linha em
     * `anexos_atendimento`: anexo sem registro clínico é o que RN28 não admite.
     */
    public function test_anexo_enviado_so_vira_registro_com_a_confirmacao(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $envio = $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender/anexos",
            ['arquivo' => UploadedFile::fake()->image('raspado-cutaneo.jpg')],
        );

        $envio->assertCreated();
        $envio->assertJsonPath('nome', 'raspado-cutaneo.jpg');
        $envio->assertJsonPath('tipo', 'imagem');

        $this->assertDatabaseCount('anexos_atendimento', 0);

        $token = $envio->json('token');

        $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender",
            $this->corpo([
                'anexos' => [[
                    'token' => $token,
                    'descricao' => 'Raspado cutâneo',
                    'exame_em' => now()->toDateString(),
                ]],
            ]),
        )->assertCreated()->assertJsonPath('anexos', 1);

        $anexo = AnexoAtendimento::query()->firstOrFail();

        $this->assertSame('Raspado cutâneo', $anexo->descricao);
        $this->assertSame('image/jpeg', $anexo->mime);
        $this->assertSame('imagem', $anexo->tipo);
        $this->assertTrue(Storage::disk(AnexoAtendimento::DISCO)->exists($anexo->caminho));

        // O arquivo saiu do rascunho: o caminho definitivo é o único que resta.
        $this->assertStringStartsWith('anexos/', $anexo->caminho);
        $this->assertEmpty(Storage::disk(AnexoAtendimento::DISCO)->files("rascunhos/anexos/{$usuario->id}"));
    }

    /** O rascunho é do usuário que o enviou, e o caminho é o que garante isso. */
    public function test_token_de_outro_profissional_nao_anexa(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);

        $marcelo = $this->marcelo($prestador);
        $larissa = $this->marcelo($prestador);

        $token = $this->actingAs($larissa)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender/anexos",
            ['arquivo' => UploadedFile::fake()->image('laudo.png')],
        )->json('token');

        $this->actingAs($marcelo)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender",
            $this->corpo([
                'anexos' => [['token' => $token, 'descricao' => 'Laudo', 'exame_em' => null]],
            ]),
        )->assertStatus(422)->assertJsonValidationErrors(['anexos.0.token']);

        $this->assertDatabaseCount('atendimentos', 0);
    }

    public function test_rascunho_de_anexo_pode_ser_descartado_antes_de_confirmar(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $token = $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender/anexos",
            ['arquivo' => UploadedFile::fake()->create('laudo.pdf', 120, 'application/pdf')],
        )->json('token');

        $this->actingAs($usuario)
            ->deleteJson("/api/clinica/animais/{$animal->codigo}/atender/anexos/{$token}")
            ->assertOk();

        $this->assertEmpty(Storage::disk(AnexoAtendimento::DISCO)->files("rascunhos/anexos/{$usuario->id}"));

        // Descartado, o token já não anexa nada — e a mensagem manda reenviar,
        // não reescrever o prontuário.
        $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender",
            $this->corpo([
                'anexos' => [['token' => $token, 'descricao' => 'Laudo', 'exame_em' => null]],
            ]),
        )->assertStatus(422)->assertJsonValidationErrors(['anexos.0.token']);
    }

    /* Imutabilidade e livro de acessos -------------------------------------- */

    public function test_nao_existe_rota_de_alteracao_nem_de_exclusao(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        foreach (['putJson', 'patchJson', 'deleteJson'] as $verbo) {
            $this->actingAs($usuario)
                ->{$verbo}("/api/clinica/animais/{$animal->codigo}/atender", [])
                ->assertStatus(405);
        }
    }

    /**
     * Com o registro imutável e sem V09, um reenvio da rede deixaria dois
     * prontuários permanentes onde houve uma consulta.
     */
    public function test_pedido_repetido_nao_cria_segundo_prontuario(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $primeiro = $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/atender", $this->corpo())
            ->assertCreated();

        $segundo = $this->actingAs($usuario)
            ->postJson("/api/clinica/animais/{$animal->codigo}/atender", $this->corpo())
            ->assertCreated();

        $this->assertSame($primeiro->json('id'), $segundo->json('id'));
        $this->assertDatabaseCount('atendimentos', 1);
    }

    /** RF52b, RN49 — abrir a tela sobre registro alheio grava a linha do livro. */
    public function test_abrir_a_tela_sobre_registro_de_outro_prestador_grava_uma_linha(): void
    {
        $prestador = $this->clinica();
        $outro = $this->clinica('Pet Center Zona Sul');
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $outro->id,
            'profissional_user_id' => User::factory()->create()->id,
        ]);

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/atender")
            ->assertOk()
            ->assertJsonPath('aviso_outro_prestador', true);

        $this->assertDatabaseCount('registros_de_acesso', 1);
        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $prestador->id,
            'user_id' => $usuario->id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
        ]);
    }

    public function test_registro_apenas_do_proprio_prestador_nao_grava_linha_de_acesso(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'profissional_user_id' => $usuario->id,
        ]);

        $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}/atender")
            ->assertOk()
            ->assertJsonPath('aviso_outro_prestador', false);

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /**
     * A ponta a ponta que interessa ao tutor: o que o veterinário escreveu em
     * V08 é o que Helena lê em T08, com a autoria à vista e o anexo servido
     * pela rota que confere a autorização (RF32c).
     */
    public function test_o_prontuario_gravado_e_o_que_o_tutor_le_depois(): void
    {
        $prestador = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $prestador);
        $usuario = $this->marcelo($prestador);

        $token = $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender/anexos",
            ['arquivo' => UploadedFile::fake()->image('raspado-cutaneo.jpg')],
        )->json('token');

        $criado = $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/atender",
            $this->corpo([
                'anexos' => [[
                    'token' => $token,
                    'descricao' => 'Raspado cutâneo',
                    'exame_em' => now()->toDateString(),
                ]],
            ]),
        )->assertCreated();

        $tutor = $animal->tutor->user;

        $leitura = $this->actingAs($tutor)->getJson(
            "/api/animais/{$animal->codigo}/atendimentos/{$criado->json('id')}"
        );

        $leitura->assertOk();
        $leitura->assertJsonPath('atendimento.aplicador.nome', 'Marcelo Andrade');
        $leitura->assertJsonPath('atendimento.aplicador.crmv', 'CRMV-MG 12345');
        $leitura->assertJsonPath('atendimento.aplicador.prestador', 'Clínica Vet Amigo');
        $leitura->assertJsonPath('atendimento.anexos.0.descricao', 'Raspado cutâneo');
        $leitura->assertJsonPath('atendimento.anexos.0.disponivel', true);

        $this->assertSame(
            'Prurido intenso e alopecia focal em região dorsal, com duas semanas de evolução.',
            collect($leitura->json('atendimento.secoes'))->firstWhere('chave', 'motivo')['texto'],
        );
    }
}
