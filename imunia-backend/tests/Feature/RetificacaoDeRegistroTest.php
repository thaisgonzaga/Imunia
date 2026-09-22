<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Autorizacao;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V09 — retificar registro clínico (RF33, RN26, RN27).
 *
 * A única escrita do sistema sobre registro clínico já existente — e a suíte
 * existe para provar que ela não escreve sobre ele. Cinco garantias:
 *
 * 1. **Nenhum caminho sobrescreve ou exclui** (RF33a): não há `PUT`, `PATCH`
 *    nem `DELETE`, e o conteúdo do original é o mesmo depois da correção.
 * 2. **O encadeamento é navegável nos dois sentidos** (RF33b).
 * 3. **Só o autor, no prestador que produziu o registro** (RF33c, RN27) — e a
 *    recusa é do servidor, não da tela (RNF09).
 * 4. **A correção declara por que existe**, e uma retificação sem alteração não
 *    é aceita.
 * 5. **A correção não vira dose**: a carteira do tutor continua contando as
 *    aplicações que houve, e não as versões que existem.
 */
class RetificacaoDeRegistroTest extends TestCase
{
    use RefreshDatabase;

    private function clinica(string $nome = 'Clínica Vet Amigo'): Prestador
    {
        return Prestador::factory()->create(['nome' => $nome]);
    }

    private function veterinario(string $nome, Prestador ...$prestadores): User
    {
        $usuario = User::factory()->create(['name' => $nome]);

        foreach ($prestadores as $prestador) {
            $usuario->prestadores()->attach($prestador, [
                'papel' => 'veterinario',
                'crmv' => '12345',
                'crmv_uf' => 'MG',
            ]);
        }

        return $usuario;
    }

    private function animalDe(string $nomeDoTutor, string $nome): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => $nomeDoTutor]);

        return Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => $nome]);
    }

    private function autorizar(Animal $animal, Prestador $prestador, string $estado = 'vigente'): void
    {
        $factory = Autorizacao::factory();

        if ($estado !== 'vigente') {
            $factory = $factory->{$estado}();
        }

        $factory->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $animal->tutor->user_id,
        ]);
    }

    private function atendimentoDe(Animal $animal, Prestador $prestador, User $autor): Atendimento
    {
        return Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'profissional_user_id' => $autor->id,
            'profissional_nome' => $autor->name,
            'profissional_crmv' => 'CRMV-MG 12345',
        ]);
    }

    /**
     * O imunobiológico e o protocolo do teste, criados **uma vez**.
     *
     * `VacinacaoFactory` traz `imunobiologico_id` e `protocolo_vacinal_id` como
     * factories encadeadas, e a segunda cria um imunobiológico por conta
     * própria — duas linhas com a mesma `chave`, que é única. Fixá-los aqui é o
     * que permite a um teste criar mais de uma aplicação.
     *
     * @return array<string, int>
     */
    private function catalogo(): array
    {
        $this->imunobiologico ??= Imunobiologico::factory()->create();
        $this->protocolo ??= ProtocoloVacinal::factory()->create([
            'imunobiologico_id' => $this->imunobiologico->id,
        ]);

        return [
            'imunobiologico_id' => $this->imunobiologico->id,
            'protocolo_vacinal_id' => $this->protocolo->id,
        ];
    }

    private ?Imunobiologico $imunobiologico = null;

    private ?ProtocoloVacinal $protocolo = null;

    private function aplicacaoDe(Animal $animal, Prestador $prestador, ?User $autor): Vacinacao
    {
        return Vacinacao::factory()->create([
            ...$this->catalogo(),
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'aplicador_user_id' => $autor?->id,
            'lote' => 'ABC-1234',
        ]);
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    private function prontuario(array $sobrescritas = []): array
    {
        return [
            'motivo_retificacao' => 'Resultado do raspado recebido três dias depois da consulta.',
            'motivo' => 'Prurido intenso e alopecia focal em região dorsal, com duas semanas de evolução.',
            'anamnese' => 'Início após passeios em área de vegetação alta. Sem alteração alimentar.',
            'exame_fisico' => 'TR 38,6 °C. Eritema e descamação em região dorsal, sem odor.',
            'hipoteses_diagnosticas' => 'Sarna sarcóptica confirmada; DAPE descartada.',
            'diagnostico' => 'Dermatite por Sarcoptes scabiei, confirmada pelo raspado cutâneo.',
            'conduta' => 'Xampu tópico duas vezes por semana. Reavaliação em três dias.',
            ...$sobrescritas,
        ];
    }

    /**
     * @param  array<string, mixed>  $sobrescritas
     * @return array<string, mixed>
     */
    private function aplicacao(Vacinacao $original, array $sobrescritas = []): array
    {
        return [
            'motivo_retificacao' => 'Lote transcrito do frasco errado na hora do registro.',
            'fabricante' => $original->fabricante,
            'lote' => $original->lote,
            'validade' => $original->validade?->format('m/Y') ?? now()->addYear()->format('m/Y'),
            'via_administracao' => $original->via_administracao ?? 'Subcutânea',
            'sitio_anatomico' => $original->sitio_anatomico,
            'aplicado_em' => ($original->aplicado_em ?? now()->subMonths(2))->format('Y-m-d\TH:i'),
            'ordem_dose' => $original->ordem_dose,
            'observacao' => $original->observacao,
            ...$sobrescritas,
        ];
    }

    // ── Leitura no ambiente clínico ──────────────────────────────────────────

    // RF33c — a ação existe para o autor, dentro do prestador que produziu o
    // registro. Para a tela, é esta chave que decide se ela desenha o botão.
    public function test_o_autor_ve_que_pode_retificar_o_proprio_registro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}?prestador={$clinica->id}")
            ->assertOk()
            ->assertJsonPath('pode_retificar', true)
            ->assertJsonPath('registro', 'atendimento')
            // O formulário abre preenchido: corrigir uma palavra do diagnóstico
            // não pode custar redigitar o prontuário inteiro.
            ->assertJsonPath('campos.0.chave', 'motivo')
            ->assertJsonPath('campos.4.chave', 'diagnostico');
    }

    // RN27 — "nenhum papel edita registro alheio". Nem o colega de equipe, que
    // vê o mesmo prontuário e trabalha na mesma clínica.
    public function test_outro_veterinario_do_mesmo_prestador_nao_pode_retificar(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $paula = $this->veterinario('Paula Nunes', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);

        $this->actingAs($paula)
            ->getJson("/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}?prestador={$clinica->id}")
            ->assertOk()
            ->assertJsonPath('pode_retificar', false);

        $this->actingAs($paula)
            ->postJson(
                "/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/retificar?prestador={$clinica->id}",
                $this->prontuario(),
            )
            ->assertForbidden();

        $this->assertSame(1, Atendimento::count());
    }

    // RF33c — "no âmbito do prestador que o produziu". O mesmo profissional,
    // atuando por outro vínculo, não retifica o que assinou pelo primeiro.
    public function test_o_autor_atuando_por_outro_prestador_nao_pode_retificar(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica('Pet Center');
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica, $outra);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $this->autorizar($theo, $outra);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}?prestador={$outra->id}")
            ->assertOk()
            ->assertJsonPath('pode_retificar', false);

        $this->actingAs($marcelo)
            ->postJson(
                "/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/retificar?prestador={$outra->id}",
                $this->prontuario(),
            )
            ->assertForbidden();
    }

    // RN48 — a autoria não dispensa o consentimento: revogada a autorização
    // (RF39), o prestador não alcança mais o registro que ele próprio produziu.
    public function test_sem_autorizacao_vigente_o_registro_nao_e_exibido(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica, 'revogada');
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}?prestador={$clinica->id}")
            ->assertForbidden();
    }

    // RN49, RF52b — abrir por endereço direto o registro de outro prestador
    // deixa a mesma linha no livro de acessos que abrir a ficha deixaria.
    public function test_abrir_registro_de_outro_prestador_grava_o_acesso(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica('Pet Center');
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $paula = $this->veterinario('Paula Nunes', $outra);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $outra, $paula);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}?prestador={$clinica->id}")
            ->assertOk();

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'animal_id' => $theo->id,
            'natureza' => RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
        ]);
    }

    // ── Retificação do prontuário ────────────────────────────────────────────

    // RF33, RF33a — a correção é registro novo; o original permanece íntegro, e
    // nenhuma coluna dele muda.
    public function test_a_retificacao_cria_registro_novo_e_preserva_o_original(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);
        $comoEstava = $atendimento->only([
            'motivo', 'anamnese', 'exame_fisico', 'hipoteses_diagnosticas', 'diagnostico', 'conduta',
        ]);

        $resposta = $this->actingAs($marcelo)->postJson(
            "/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/retificar?prestador={$clinica->id}",
            $this->prontuario(),
        );

        $resposta->assertCreated();

        $retificacao = Atendimento::findOrFail($resposta->json('id'));

        $this->assertSame($atendimento->id, $retificacao->retifica_atendimento_id);
        $this->assertSame(
            'Dermatite por Sarcoptes scabiei, confirmada pelo raspado cutâneo.',
            $retificacao->diagnostico,
        );
        $this->assertSame(
            'Resultado do raspado recebido três dias depois da consulta.',
            $retificacao->motivo_retificacao,
        );

        // A consulta aconteceu quando aconteceu: a correção não inventa um
        // atendimento novo em outra data.
        $this->assertTrue($retificacao->atendido_em->equalTo($atendimento->atendido_em));

        // O original, campo a campo, é exatamente o que era.
        $this->assertSame($comoEstava, $atendimento->fresh()->only(array_keys($comoEstava)));
        $this->assertNull($atendimento->fresh()->motivo_retificacao);
    }

    // RF33b — o encadeamento é navegável nos dois sentidos, e a comparação diz
    // o que mudou.
    public function test_o_encadeamento_e_navegavel_nos_dois_sentidos(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);

        // Só dois campos mudam: o resto do corpo repete o que estava gravado,
        // que é o que o formulário de V09 envia — ele abre preenchido.
        $corpo = [
            ...$this->prontuario(),
            ...collect(['motivo', 'anamnese', 'exame_fisico', 'conduta'])
                ->mapWithKeys(fn (string $chave) => [$chave => $atendimento->{$chave}])
                ->all(),
        ];

        $criada = $this->actingAs($marcelo)->postJson(
            "/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/retificar?prestador={$clinica->id}",
            $corpo,
        )->json('id');

        $doOriginal = $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}?prestador={$clinica->id}");

        $doOriginal->assertOk()
            ->assertJsonPath('retificacao.id', $criada)
            ->assertJsonPath('original', null)
            // Já retificado: a segunda correção é da correção, e não desta.
            ->assertJsonPath('pode_retificar', false);

        $daRetificacao = $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/atendimentos/{$criada}?prestador={$clinica->id}");

        $daRetificacao->assertOk()
            ->assertJsonPath('original.id', $atendimento->id)
            ->assertJsonPath('retificacao', null)
            ->assertJsonPath('atendimento.eh_retificacao', true)
            ->assertJsonPath('pode_retificar', true);

        $alterados = collect($daRetificacao->json('original.campos_alterados'))->pluck('chave');
        $this->assertEqualsCanonicalizing(['hipoteses_diagnosticas', 'diagnostico'], $alterados->all());
    }

    // RN26 — a cadeia é uma linha, não uma árvore: duas correções do mesmo
    // original seriam duas versões vigentes ao mesmo tempo.
    public function test_registro_ja_retificado_nao_admite_segunda_retificacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);

        $caminho = "/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/retificar"
            ."?prestador={$clinica->id}";

        $this->actingAs($marcelo)->postJson($caminho, $this->prontuario())->assertCreated();
        $this->actingAs($marcelo)->postJson($caminho, $this->prontuario())->assertForbidden();

        $this->assertSame(2, Atendimento::count());
    }

    // RF33 — o motivo é obrigatório: é ele que explica a correção a quem ler o
    // prontuário depois, e é ele que o tutor vê ao lado das duas versões.
    public function test_a_retificacao_sem_motivo_e_recusada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);

        $this->actingAs($marcelo)
            ->postJson(
                "/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/retificar?prestador={$clinica->id}",
                $this->prontuario(['motivo_retificacao' => '']),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo_retificacao');

        $this->assertSame(1, Atendimento::count());
    }

    // Uma retificação idêntica acrescentaria uma versão ao prontuário sem
    // acrescentar informação alguma — e o histórico anunciaria uma correção que
    // não corrigiu nada.
    public function test_a_retificacao_sem_alteracao_e_recusada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);

        $iguais = collect(['motivo', 'anamnese', 'exame_fisico', 'hipoteses_diagnosticas', 'diagnostico', 'conduta'])
            ->mapWithKeys(fn (string $chave) => [$chave => $atendimento->{$chave}])
            ->all();

        $this->actingAs($marcelo)
            ->postJson(
                "/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/retificar?prestador={$clinica->id}",
                $this->prontuario($iguais),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->assertSame(1, Atendimento::count());
    }

    // RF33a — "nenhum caminho da aplicação permite sobrescrever ou excluir
    // registro clínico confirmado". A prova é a ausência do verbo.
    public function test_nao_existe_caminho_que_altere_ou_exclua_registro_clinico(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $atendimento = $this->atendimentoDe($theo, $clinica, $marcelo);
        $aplicacao = $this->aplicacaoDe($theo, $clinica, $marcelo);

        $caminhos = [
            "/api/clinica/animais/{$theo->codigo}/atendimentos/{$atendimento->id}",
            "/api/clinica/animais/{$theo->codigo}/vacinas/{$aplicacao->id}",
            "/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}",
            "/api/animais/{$theo->codigo}/vacinas/{$aplicacao->id}",
        ];

        foreach ($caminhos as $caminho) {
            foreach (['putJson', 'patchJson', 'deleteJson'] as $verbo) {
                $resposta = $this->actingAs($marcelo)->{$verbo}("{$caminho}?prestador={$clinica->id}");

                $this->assertContains(
                    $resposta->status(),
                    [404, 405],
                    "{$verbo} em {$caminho} respondeu {$resposta->status()} — a rota não deveria existir.",
                );
            }
        }
    }

    // ── Retificação da aplicação de vacina ───────────────────────────────────

    public function test_a_retificacao_da_aplicacao_cria_versao_nova_e_preserva_a_original(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $aplicacao = $this->aplicacaoDe($theo, $clinica, $marcelo);

        $resposta = $this->actingAs($marcelo)->postJson(
            "/api/clinica/animais/{$theo->codigo}/vacinas/{$aplicacao->id}/retificar?prestador={$clinica->id}",
            $this->aplicacao($aplicacao, ['lote' => 'XPT-9090']),
        );

        $resposta->assertCreated();

        $retificacao = Vacinacao::findOrFail($resposta->json('id'));

        $this->assertSame($aplicacao->id, $retificacao->retifica_vacinacao_id);
        $this->assertSame('XPT-9090', $retificacao->lote);
        $this->assertSame($marcelo->id, $retificacao->aplicador_user_id);

        // RN32 — a versão do protocolo continua sendo a que valia à época: a
        // correção de um lote não reabre o cálculo sob diretriz posterior.
        $this->assertSame($aplicacao->protocolo_vacinal_id, $retificacao->protocolo_vacinal_id);

        $this->assertSame('ABC-1234', $aplicacao->fresh()->lote);
    }

    /**
     * O ponto que a fatia existe para não errar: uma aplicação e a sua
     * retificação descrevem **uma** dose. Contá-las como duas deslocaria o
     * rótulo de todas as doses seguintes e a data da próxima (RF26) — o tutor
     * veria a carteira ganhar uma dose porque alguém corrigiu um número de lote.
     */
    public function test_a_retificacao_nao_conta_como_segunda_dose_na_carteira(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);

        $aplicacao = $this->aplicacaoDe($theo, $clinica, $marcelo);

        $tutor = $theo->tutor->user;

        $antes = $this->actingAs($tutor)->getJson("/api/animais/{$theo->codigo}/carteira");
        $antes->assertOk()->assertJsonCount(1, 'grupos.0.aplicacoes');
        $proximaAntes = $antes->json('grupos.0.proxima_dose.prevista_para');

        $this->actingAs($marcelo)->postJson(
            "/api/clinica/animais/{$theo->codigo}/vacinas/{$aplicacao->id}/retificar?prestador={$clinica->id}",
            $this->aplicacao($aplicacao, ['lote' => 'XPT-9090']),
        )->assertCreated();

        $depois = $this->actingAs($tutor)->getJson("/api/animais/{$theo->codigo}/carteira");

        $depois->assertOk()
            ->assertJsonCount(1, 'grupos.0.aplicacoes')
            // É a versão corrigida que a carteira exibe: o lote que o tutor lê
            // é o que está no frasco.
            ->assertJsonPath('grupos.0.aplicacoes.0.lote', 'XPT-9090')
            ->assertJsonPath('grupos.0.proxima_dose.prevista_para', $proximaAntes);

        // E as duas versões continuam existindo, ambas alcançáveis.
        $this->assertSame(2, Vacinacao::count());
    }

    // RF33b, no histórico consolidado: a versão substituída permanece na linha
    // do tempo, "íntegra e visível, sinalizada como retificada".
    public function test_as_duas_versoes_da_aplicacao_ficam_no_historico(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $aplicacao = $this->aplicacaoDe($theo, $clinica, $marcelo);

        $criada = $this->actingAs($marcelo)->postJson(
            "/api/clinica/animais/{$theo->codigo}/vacinas/{$aplicacao->id}/retificar?prestador={$clinica->id}",
            $this->aplicacao($aplicacao, ['lote' => 'XPT-9090']),
        )->json('id');

        $historico = $this->actingAs($theo->tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/historico");

        $historico->assertOk();

        $entradas = collect($historico->json('entradas'))->keyBy('id');

        $this->assertSame('retificacao', $entradas[$criada]['tipo']);
        $this->assertSame($aplicacao->id, $entradas[$criada]['vinculada_a']);
        $this->assertSame('vacinacao', $entradas[$aplicacao->id]['tipo']);
        $this->assertStringContainsString('corrigido depois', $entradas[$aplicacao->id]['resumo']);
    }

    // RN25 — o histórico pregresso é lançamento do tutor, não ato clínico: não
    // tem aplicador, e não há profissional a quem a correção coubesse.
    public function test_o_historico_pregresso_nao_e_retificavel_pelo_veterinario(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);

        $pregresso = Vacinacao::factory()->pregresso()->create([
            ...$this->catalogo(),
            'animal_id' => $theo->id,
            'lancado_por_user_id' => $theo->tutor->user_id,
        ]);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/vacinas/{$pregresso->id}?prestador={$clinica->id}")
            ->assertOk()
            ->assertJsonPath('pode_retificar', false);

        $this->actingAs($marcelo)
            ->postJson(
                "/api/clinica/animais/{$theo->codigo}/vacinas/{$pregresso->id}/retificar?prestador={$clinica->id}",
                $this->aplicacao($pregresso, ['lote' => 'XPT-9090']),
            )
            ->assertForbidden();
    }

    public function test_a_retificacao_da_aplicacao_sem_alteracao_e_recusada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);
        $aplicacao = $this->aplicacaoDe($theo, $clinica, $marcelo);

        $this->actingAs($marcelo)
            ->postJson(
                "/api/clinica/animais/{$theo->codigo}/vacinas/{$aplicacao->id}/retificar?prestador={$clinica->id}",
                $this->aplicacao($aplicacao),
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('lote');

        $this->assertSame(1, Vacinacao::count());
    }

    /**
     * RF25c — a marca de validade expirada é recalculada a partir das datas
     * corrigidas. Mantê-la como estava deixaria no registro uma afirmação que
     * os seus próprios campos desmentem.
     */
    public function test_a_marca_de_validade_expirada_e_recalculada_na_retificacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->veterinario('Marcelo Andrade', $clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);

        $aplicacao = Vacinacao::factory()->create([
            ...$this->catalogo(),
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'aplicador_user_id' => $marcelo->id,
            'aplicado_em' => now()->subMonths(2),
            'validade' => now()->subYear()->endOfMonth()->toDateString(),
            'validade_expirada_confirmada' => true,
        ]);

        // A validade fora digitada errada: o frasco valia até o ano que vem.
        $resposta = $this->actingAs($marcelo)->postJson(
            "/api/clinica/animais/{$theo->codigo}/vacinas/{$aplicacao->id}/retificar?prestador={$clinica->id}",
            $this->aplicacao($aplicacao, ['validade' => now()->addYear()->format('m/Y')]),
        );

        $resposta->assertCreated();

        $this->assertFalse(Vacinacao::findOrFail($resposta->json('id'))->validade_expirada_confirmada);
        $this->assertTrue($aplicacao->fresh()->validade_expirada_confirmada);
    }
}
