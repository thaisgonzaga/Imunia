<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\ConfirmacaoDeAutorizacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\AutorizacaoBloqueadaPorTentativas;
use App\Notifications\CodigoDeAutorizacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * T11 — conceder autorização (RF36, RF37, RN37, RN38, RN39).
 *
 * O que estes testes protegem, antes de qualquer detalhe de resposta, é a
 * separação entre escolher e consentir: nenhuma linha de autorização pode
 * existir antes do código de volta, e cada linha que existir tem de ser de um
 * animal determinado.
 */
class AutorizacaoControllerTest extends TestCase
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

    private function clinica(): Prestador
    {
        return Prestador::factory()->create([
            'nome' => 'Clínica Vet Amigo',
            'municipio' => 'Viçosa',
            'uf' => 'MG',
        ]);
    }

    /**
     * Abre uma confirmação pelo caminho da API e devolve o resumo com o código
     * que foi para o e-mail — que é o único lugar onde ele existe.
     *
     * @param  list<string>  $animais
     * @return array{0: ConfirmacaoDeAutorizacao, 1: string}
     */
    private function pedirCodigo(Tutor $tutor, Prestador $prestador, array $animais): array
    {
        $this->actingAs($tutor->user)
            ->postJson('/api/autorizacoes/confirmacoes', [
                'prestador' => $prestador->id,
                'animais' => $animais,
            ])
            ->assertCreated();

        $codigo = null;

        Notification::assertSentTo(
            $tutor->user,
            CodigoDeAutorizacao::class,
            function (CodigoDeAutorizacao $notificacao) use (&$codigo) {
                $codigo = $notificacao->codigo;

                return true;
            },
        );

        return [ConfirmacaoDeAutorizacao::query()->latest('id')->firstOrFail(), $codigo];
    }

    // Guardas de âmbito — as mesmas do restante do ambiente do tutor. -------

    public function test_o_fluxo_exige_sessao(): void
    {
        $this->getJson('/api/autorizacoes/nova')->assertUnauthorized();
        $this->postJson('/api/autorizacoes/confirmacoes')->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_concede(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/autorizacoes/nova')
            ->assertForbidden();
    }

    // As opções do fluxo ---------------------------------------------------

    public function test_as_opcoes_trazem_o_prestador_escolhido_os_animais_e_o_prazo(): void
    {
        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);

        $resposta = $this->actingAs($helena->user)
            ->getJson('/api/autorizacoes/nova?prestador='.$clinica->id)
            ->assertOk();

        $resposta->assertJsonPath('prestador_escolhido', $clinica->id);
        $resposta->assertJsonPath('prazo_em_dias', Autorizacao::PRAZO_DIAS);
        $resposta->assertJsonPath('prestadores.0.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('animais.0.codigo', $theo->codigo);
        $resposta->assertJsonPath('tutor.email_verificado', true);
    }

    /**
     * O passo 2 precisa saber quem já está autorizado ali para não oferecer a
     * marcação de novo — e para não produzir duas linhas vigentes do mesmo par.
     */
    public function test_as_opcoes_apontam_o_animal_ja_autorizado_no_prestador(): void
    {
        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);
        Animal::factory()->gato()->create(['tutor_id' => $helena->id, 'nome' => 'Nina']);

        Autorizacao::factory()->create([
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $helena->user_id,
        ]);

        $this->actingAs($helena->user)
            ->getJson('/api/autorizacoes/nova?prestador='.$clinica->id)
            ->assertOk()
            ->assertJsonPath('prestadores.0.autorizados.0.codigo', $theo->codigo)
            ->assertJsonCount(1, 'prestadores.0.autorizados')
            ->assertJsonCount(2, 'animais');
    }

    // O pedido do código ---------------------------------------------------

    /**
     * RF37c — o código sai pelo e-mail e por lugar nenhum mais. E RF36b: até
     * aqui, nada foi autorizado.
     */
    public function test_o_pedido_envia_o_codigo_por_email_sem_devolve_lo_e_sem_conceder_nada(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);

        $resposta = $this->actingAs($helena->user)
            ->postJson('/api/autorizacoes/confirmacoes', [
                'prestador' => $clinica->id,
                'animais' => [$theo->codigo],
            ])
            ->assertCreated();

        $resposta->assertJsonPath('confirmacao.prestador.nome', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('confirmacao.animais.0.nome', 'Théo');
        $resposta->assertJsonPath('confirmacao.tentativas_restantes', ConfirmacaoDeAutorizacao::TENTATIVAS);

        $codigo = null;
        Notification::assertSentTo(
            $helena->user,
            CodigoDeAutorizacao::class,
            function (CodigoDeAutorizacao $notificacao) use (&$codigo) {
                $codigo = $notificacao->codigo;

                return true;
            },
        );

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $codigo);
        $this->assertStringNotContainsString((string) $codigo, $resposta->getContent());
        $this->assertSame(0, Autorizacao::query()->count());
    }

    /**
     * RF37b — endereço não confirmado interrompe o fluxo, e a tela recebe a
     * situação nomeada para entrar no estado que explica o que fazer.
     */
    public function test_tutor_com_email_nao_confirmado_nao_recebe_codigo(): void
    {
        Notification::fake();

        $usuario = User::factory()->unverified()->create();
        $helena = Tutor::factory()->create(['user_id' => $usuario->id]);
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        $this->actingAs($usuario)
            ->postJson('/api/autorizacoes/confirmacoes', [
                'prestador' => $clinica->id,
                'animais' => [$theo->codigo],
            ])
            ->assertForbidden()
            ->assertJsonPath('situacao', 'email_nao_verificado');

        Notification::assertNothingSent();
        $this->assertSame(0, ConfirmacaoDeAutorizacao::query()->count());
    }

    /** RN12 — animal de outro tutor não se distingue de código inexistente. */
    public function test_animal_de_outro_tutor_nao_entra_na_confirmacao(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $bidu = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $this->actingAs($helena->user)
            ->postJson('/api/autorizacoes/confirmacoes', [
                'prestador' => $clinica->id,
                'animais' => [$bidu->codigo],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('animais');

        Notification::assertNothingSent();
    }

    public function test_a_escolha_sem_animal_algum_e_recusada(): void
    {
        $helena = $this->helena();
        $clinica = $this->clinica();

        $this->actingAs($helena->user)
            ->postJson('/api/autorizacoes/confirmacoes', [
                'prestador' => $clinica->id,
                'animais' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('animais');
    }

    public function test_animal_ja_autorizado_no_prestador_nao_e_autorizado_de_novo(): void
    {
        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);

        Autorizacao::factory()->create([
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $helena->user_id,
        ]);

        $this->actingAs($helena->user)
            ->postJson('/api/autorizacoes/confirmacoes', [
                'prestador' => $clinica->id,
                'animais' => [$theo->codigo],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('animais');
    }

    // A confirmação --------------------------------------------------------

    /**
     * O ato. Uma linha por animal (RN37), noventa dias contados da confirmação
     * (RN39), e a autoria de quem consentiu (RF36d).
     */
    public function test_o_codigo_correto_concede_uma_autorizacao_por_animal(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);
        $nina = Animal::factory()->gato()->create(['tutor_id' => $helena->id, 'nome' => 'Nina']);

        [$confirmacao, $codigo] = $this->pedirCodigo($helena, $clinica, [$theo->codigo, $nina->codigo]);

        $resposta = $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $codigo])
            ->assertCreated();

        $resposta->assertJsonPath('situacao', 'concedida');
        $resposta->assertJsonCount(2, 'concessao.animais');
        $resposta->assertJsonPath('concessao.prazo_em_dias', Autorizacao::PRAZO_DIAS);

        $this->assertSame(2, Autorizacao::query()->count());

        foreach ([$theo, $nina] as $animal) {
            $autorizacao = Autorizacao::query()
                ->where('animal_id', $animal->id)
                ->where('prestador_id', $clinica->id)
                ->firstOrFail();

            $this->assertTrue($autorizacao->estaVigente());
            $this->assertSame($helena->user_id, $autorizacao->concedida_por_user_id);
            $this->assertSame(
                now()->addDays(Autorizacao::PRAZO_DIAS)->toDateString(),
                $autorizacao->expira_em->toDateString(),
            );
        }
    }

    public function test_o_codigo_errado_nao_concede_e_devolve_as_tentativas_restantes(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        [$confirmacao] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('situacao', 'codigo_incorreto')
            ->assertJsonPath('tentativas_restantes', ConfirmacaoDeAutorizacao::TENTATIVAS - 1);

        $this->assertSame(0, Autorizacao::query()->count());
    }

    /**
     * RF37a — o limite de tentativas, e o aviso que o acompanha: se não foi o
     * tutor quem digitou, é pelo e-mail que ele fica sabendo.
     */
    public function test_cinco_codigos_errados_bloqueiam_a_concessao_e_avisam_o_tutor(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        [$confirmacao, $codigo] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);

        for ($tentativa = 1; $tentativa < ConfirmacaoDeAutorizacao::TENTATIVAS; $tentativa++) {
            $this->actingAs($helena->user)
                ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => '000000'])
                ->assertStatus(422);
        }

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => '000000'])
            ->assertStatus(429)
            ->assertJsonPath('situacao', 'bloqueada');

        Notification::assertSentTo($helena->user, AutorizacaoBloqueadaPorTentativas::class);

        // E o código verdadeiro tampouco passa enquanto a pausa corre: bloquear
        // só as tentativas erradas não bloquearia coisa alguma.
        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $codigo])
            ->assertStatus(429);

        $this->assertSame(0, Autorizacao::query()->count());
    }

    public function test_codigo_expirado_nao_concede(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        [$confirmacao, $codigo] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);

        $confirmacao->forceFill(['expira_em' => now()->subMinute()])->save();

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $codigo])
            ->assertStatus(410)
            ->assertJsonPath('situacao', 'codigo_expirado');

        $this->assertSame(0, Autorizacao::query()->count());
    }

    /** Uso único: o código gasto não concede a segunda vez. */
    public function test_o_codigo_nao_serve_duas_vezes(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        [$confirmacao, $codigo] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $codigo])
            ->assertCreated();

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $codigo])
            ->assertStatus(409)
            ->assertJsonPath('situacao', 'ja_confirmada');

        $this->assertSame(1, Autorizacao::query()->count());
    }

    public function test_confirmacao_de_outro_usuario_responde_404(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        [$confirmacao, $codigo] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);

        $intruso = Tutor::factory()->create(['nome' => 'Marcos Lima']);

        $this->actingAs($intruso->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $codigo])
            ->assertNotFound();

        $this->assertSame(0, Autorizacao::query()->count());
    }

    /**
     * O código anterior perde a validade quando o tutor recomeça — dois códigos
     * válidos deixariam o da mensagem antiga conceder o que a nova propõe.
     */
    public function test_pedir_codigo_de_novo_invalida_o_anterior(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);
        $nina = Animal::factory()->gato()->create(['tutor_id' => $helena->id, 'nome' => 'Nina']);

        [$primeira, $codigoAntigo] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);
        [$segunda] = $this->pedirCodigo($helena, $clinica, [$nina->codigo]);

        $this->assertNotSame($primeira->publico, $segunda->publico);

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$primeira->publico}", ['codigo' => $codigoAntigo])
            ->assertStatus(410);

        $this->assertSame(0, Autorizacao::query()->count());
    }

    // O reenvio ------------------------------------------------------------

    public function test_o_reenvio_so_libera_depois_que_o_codigo_vence(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        [$confirmacao] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}/reenviar")
            ->assertStatus(429)
            ->assertJsonPath('situacao', 'reenvio_bloqueado');
    }

    public function test_o_reenvio_manda_codigo_novo_e_aposenta_o_anterior(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        [$confirmacao, $antigo] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);

        $confirmacao->forceFill(['expira_em' => now()->subMinute()])->save();

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}/reenviar")
            ->assertOk()
            ->assertJsonPath('confirmacao.id', $confirmacao->publico);

        $novo = null;
        Notification::assertSentTimes(CodigoDeAutorizacao::class, 2);
        Notification::assertSentTo(
            $helena->user,
            CodigoDeAutorizacao::class,
            function (CodigoDeAutorizacao $notificacao) use (&$novo, $antigo) {
                if ($notificacao->codigo !== $antigo) {
                    $novo = $notificacao->codigo;
                }

                return true;
            },
        );

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $antigo])
            ->assertStatus(422);

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $novo])
            ->assertCreated();

        $this->assertSame(1, Autorizacao::query()->count());
    }

    /**
     * O reenvio não devolve tentativas: quatro erros e um código novo não podem
     * reabrir a contagem, ou o limite de RF37a nunca seria alcançado.
     */
    public function test_o_reenvio_nao_devolve_tentativas(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $clinica = $this->clinica();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        [$confirmacao] = $this->pedirCodigo($helena, $clinica, [$theo->codigo]);

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => '000000'])
            ->assertStatus(422);

        $confirmacao->forceFill(['expira_em' => now()->subMinute()])->save();

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}/reenviar")
            ->assertOk()
            ->assertJsonPath('confirmacao.tentativas_restantes', ConfirmacaoDeAutorizacao::TENTATIVAS - 1);
    }
}
