<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\ConfirmacaoDeAutorizacao;
use App\Models\Prestador;
use App\Models\SolicitacaoAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\CodigoDeAutorizacao;
use App\Notifications\SolicitacaoDeAcessoRecusada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * T13 — solicitações de acesso (RF38).
 *
 * O que estes testes protegem, antes de qualquer detalhe de resposta, é a
 * assimetria entre pedir e conceder: o pedido não abre porta alguma, a recusa
 * não custa nada ao tutor, e o "sim" continua saindo apenas pelo fluxo de T11,
 * com código enviado ao endereço do titular.
 */
class SolicitacoesAcessoTest extends TestCase
{
    use RefreshDatabase;

    private function helena(): Tutor
    {
        $usuario = User::factory()->create([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
        ]);

        return Tutor::factory()->create(['user_id' => $usuario->id, 'nome' => 'Helena Ramos']);
    }

    private function animal(Tutor $tutor, string $nome, string $especie = 'cao'): Animal
    {
        return Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nome' => $nome,
            'especie' => $especie,
        ]);
    }

    /**
     * O prestador com um veterinário vinculado — é a ele que a recusa é
     * comunicada, e sem o vínculo não haveria a quem comunicar.
     */
    private function prestador(string $nome): Prestador
    {
        $prestador = Prestador::factory()->create([
            'nome' => $nome,
            'municipio' => 'Viçosa',
            'uf' => 'MG',
        ]);

        $prestador->usuarios()->attach(
            User::factory()->create()->id,
            ['papel' => 'veterinario', 'crmv' => '12345', 'crmv_uf' => 'MG'],
        );

        return $prestador;
    }

    private function pedir(Animal $animal, Prestador $prestador, string ...$estados): SolicitacaoAcesso
    {
        $factory = SolicitacaoAcesso::factory();

        foreach ($estados as $estado) {
            $factory = $factory->{$estado}();
        }

        return $factory->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'solicitada_por_user_id' => $prestador->usuarios->first()->id,
        ]);
    }

    // Guardas de âmbito — as mesmas do restante do ambiente do tutor. -------

    public function test_a_relacao_exige_sessao(): void
    {
        $this->getJson('/api/solicitacoes')->assertUnauthorized();
        $this->getJson('/api/solicitacoes/pendentes')->assertUnauthorized();
    }

    public function test_quem_nao_e_tutor_nao_alcanca_os_pedidos(): void
    {
        // Quem pede não acompanha o pedido por aqui: RF38a manda que a
        // solicitação pendente não revele coisa alguma ao solicitante, e esta
        // relação é a do titular.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/solicitacoes')
            ->assertForbidden();
    }

    public function test_o_pedido_de_animal_de_outro_tutor_nao_aparece(): void
    {
        $helena = $this->helena();
        $this->animal($helena, 'Théo');

        $outro = Tutor::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->pedir($this->animal($outro, 'Amora'), $this->prestador('Hospital Bicho Bom'));

        $this->actingAs($helena->user)
            ->getJson('/api/solicitacoes')
            ->assertOk()
            ->assertJsonPath('pendentes', 0)
            ->assertJsonCount(0, 'solicitacoes');
    }

    // A relação -------------------------------------------------------------

    public function test_a_relacao_traz_prestador_animal_data_e_situacao(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $hospital = $this->prestador('Hospital Bicho Bom');

        $this->pedir($theo, $hospital);

        $resposta = $this->actingAs($helena->user)->getJson('/api/solicitacoes')->assertOk();

        $resposta->assertJsonPath('prazo_em_dias', SolicitacaoAcesso::PRAZO_DIAS);
        $resposta->assertJsonPath('pendentes', 1);
        $resposta->assertJsonPath('solicitacoes.0.situacao', 'pendente');
        $resposta->assertJsonPath('solicitacoes.0.prestador.nome', 'Hospital Bicho Bom');
        $resposta->assertJsonPath('solicitacoes.0.animal.nome', 'Théo');
        $resposta->assertJsonPath('solicitacoes.0.dias_restantes', SolicitacaoAcesso::PRAZO_DIAS);
    }

    public function test_o_pendente_vem_antes_do_que_ja_terminou(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $nina = $this->animal($helena, 'Nina', 'gato');

        $this->pedir($theo, $this->prestador('Clínica São Bento'), 'expirada');
        $this->pedir($nina, $this->prestador('Pet Center Zona Sul'), 'recusada');
        $this->pedir($theo, $this->prestador('Hospital Bicho Bom'));

        $resposta = $this->actingAs($helena->user)->getJson('/api/solicitacoes')->assertOk();

        $resposta->assertJsonPath('pendentes', 1);
        $resposta->assertJsonPath('solicitacoes.0.situacao', 'pendente');
        $resposta->assertJsonCount(3, 'solicitacoes');
    }

    public function test_o_pedido_ja_atendido_sai_da_relacao(): void
    {
        // Virou autorização: quem o acompanha é T12, e mantê-lo aqui cobraria
        // do tutor uma resposta que ele já deu.
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');

        $this->pedir($theo, $this->prestador('Hospital Bicho Bom'), 'atendida');

        $this->actingAs($helena->user)
            ->getJson('/api/solicitacoes')
            ->assertOk()
            ->assertJsonCount(0, 'solicitacoes');
    }

    public function test_o_contador_conta_apenas_o_que_espera_resposta(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');

        $this->pedir($theo, $this->prestador('Hospital Bicho Bom'));
        $this->pedir($theo, $this->prestador('Clínica São Bento'), 'expirada');
        $this->pedir($theo, $this->prestador('Pet Center Zona Sul'), 'recusada');

        $this->actingAs($helena->user)
            ->getJson('/api/solicitacoes/pendentes')
            ->assertOk()
            ->assertJsonPath('pendentes', 1);
    }

    // A recusa --------------------------------------------------------------

    public function test_recusar_encerra_o_pedido_e_avisa_o_prestador(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $hospital = $this->prestador('Hospital Bicho Bom');
        $pedido = $this->pedir($theo, $hospital);

        $this->actingAs($helena->user)
            ->postJson("/api/solicitacoes/{$pedido->id}/recusar")
            ->assertOk()
            ->assertJsonPath('situacao', 'recusada');

        $this->assertNotNull($pedido->fresh()->recusada_em);

        // A recusa não concede nem retira acesso: o que ela encerra é a
        // pergunta.
        $this->assertSame(0, Autorizacao::query()->count());

        Notification::assertSentTo(
            $hospital->usuarios->first(),
            SolicitacaoDeAcessoRecusada::class,
        );
    }

    public function test_recusar_duas_vezes_termina_no_mesmo_lugar(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $pedido = $this->pedir($theo, $this->prestador('Hospital Bicho Bom'));

        $this->actingAs($helena->user)->postJson("/api/solicitacoes/{$pedido->id}/recusar")->assertOk();
        $recusadaEm = $pedido->fresh()->recusada_em;

        $this->actingAs($helena->user)
            ->postJson("/api/solicitacoes/{$pedido->id}/recusar")
            ->assertOk()
            ->assertJsonPath('situacao', 'recusada');

        // A data é a da primeira recusa: o segundo toque não reescreve o ato.
        $this->assertTrue($recusadaEm->equalTo($pedido->fresh()->recusada_em));

        Notification::assertSentToTimes(
            $pedido->prestador->usuarios->first(),
            SolicitacaoDeAcessoRecusada::class,
            1,
        );
    }

    public function test_o_pedido_caducado_nao_se_recusa(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $pedido = $this->pedir($theo, $this->prestador('Clínica São Bento'), 'expirada');

        $this->actingAs($helena->user)
            ->postJson("/api/solicitacoes/{$pedido->id}/recusar")
            ->assertStatus(409)
            ->assertJsonPath('situacao', 'expirada');

        $this->assertNull($pedido->fresh()->recusada_em);
    }

    public function test_o_pedido_de_outro_tutor_nao_existe_para_este(): void
    {
        // 404 e não 403: a segunda resposta confirmaria que a linha existe.
        $helena = $this->helena();
        $this->animal($helena, 'Théo');

        $outro = Tutor::factory()->create(['user_id' => User::factory()->create()->id]);
        $pedido = $this->pedir($this->animal($outro, 'Amora'), $this->prestador('Hospital Bicho Bom'));

        $this->actingAs($helena->user)
            ->postJson("/api/solicitacoes/{$pedido->id}/recusar")
            ->assertNotFound();
    }

    // A baixa pela concessão (RF36 + RF38) ---------------------------------

    public function test_conceder_a_autorizacao_da_baixa_no_pedido(): void
    {
        Notification::fake();

        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $hospital = $this->prestador('Hospital Bicho Bom');
        $pedido = $this->pedir($theo, $hospital);

        $codigo = null;

        $this->actingAs($helena->user)
            ->postJson('/api/autorizacoes/confirmacoes', [
                'prestador' => $hospital->id,
                'animais' => [$theo->codigo],
            ])
            ->assertCreated();

        Notification::assertSentTo(
            $helena->user,
            CodigoDeAutorizacao::class,
            function (CodigoDeAutorizacao $notificacao) use (&$codigo) {
                $codigo = $notificacao->codigo;

                return true;
            },
        );

        $confirmacao = ConfirmacaoDeAutorizacao::query()->latest('id')->firstOrFail();

        $this->actingAs($helena->user)
            ->postJson("/api/autorizacoes/confirmacoes/{$confirmacao->publico}", ['codigo' => $codigo])
            ->assertCreated();

        $this->assertNotNull($pedido->fresh()->atendida_em);

        // E some da tela, porque a pergunta virou acesso.
        $this->actingAs($helena->user)
            ->getJson('/api/solicitacoes')
            ->assertOk()
            ->assertJsonPath('pendentes', 0)
            ->assertJsonCount(0, 'solicitacoes');
    }
}
