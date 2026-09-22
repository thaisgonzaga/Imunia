<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Notificacao;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T17 — histórico de notificações (RF45), a leitura pelo tutor do registro que
 * o motor de RF42 escreve e V02 já consulta.
 */
class HistoricoDeNotificacoesTest extends TestCase
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

    private function animal(Tutor $tutor, string $nome): Animal
    {
        return Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => $nome]);
    }

    private function v10(): Imunobiologico
    {
        return Imunobiologico::factory()->create([
            'chave' => 'v10-multipla-canina',
            'nome_comercial' => 'V10 — múltipla canina',
        ]);
    }

    public function test_o_historico_exige_sessao(): void
    {
        $this->getJson('/api/conta/notificacoes/enviadas')->assertUnauthorized();
    }

    public function test_quem_nao_e_tutor_nao_alcanca_o_historico(): void
    {
        // A visão do veterinário (RF45a) tem outro âmbito e é outra fatia.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/conta/notificacoes/enviadas')
            ->assertForbidden();
    }

    public function test_a_relacao_traz_data_tipo_animal_destinatario_e_situacao(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');
        $v10 = $this->v10();

        Notificacao::factory()->create([
            'animal_id' => $theo->id,
            'tutor_id' => $helena->id,
            'imunobiologico_id' => $v10->id,
            'tipo' => 'aviso_previo',
            'referente_a' => '2026-09-17',
            'enviada_em' => '2026-09-10 08:00:00',
        ]);
        Notificacao::factory()->alertaDeAtraso()->falhou()->create([
            'animal_id' => $theo->id,
            'tutor_id' => $helena->id,
            'imunobiologico_id' => $v10->id,
            'referente_a' => '2026-09-17',
            'enviada_em' => '2026-09-20 08:00:00',
        ]);

        $resposta = $this->actingAs($helena->user)
            ->getJson('/api/conta/notificacoes/enviadas')
            ->assertOk();

        $resposta->assertJsonPath('total', 2);

        // Da mais recente para a mais antiga: a pergunta do tutor é sobre o
        // lembrete que ele esperava ter recebido agora.
        $resposta->assertJsonPath('notificacoes.0.tipo', 'alerta_atraso');
        $resposta->assertJsonPath('notificacoes.0.tipo_rotulo', 'Alerta de atraso');
        $resposta->assertJsonPath('notificacoes.0.data', '2026-09-20');
        $resposta->assertJsonPath('notificacoes.0.hora', '08:00');
        $resposta->assertJsonPath('notificacoes.0.animal.nome', 'Théo');
        $resposta->assertJsonPath('notificacoes.0.animal.do_tutor', true);
        $resposta->assertJsonPath('notificacoes.0.vacina', 'V10 — múltipla canina');
        $resposta->assertJsonPath('notificacoes.0.referente_a', '2026-09-17');

        // Destinatário mascarado, como a confirmação de e-mail o exibe.
        $resposta->assertJsonPath('notificacoes.0.destinatario', 'hel•••@example.com');

        // A falha de envio vem explicada em linguagem simples, não só nomeada.
        $resposta->assertJsonPath('notificacoes.0.situacao', 'falhou');
        $resposta->assertJsonPath('notificacoes.0.situacao_rotulo', 'Não entregue');
        $this->assertStringContainsString(
            'recusou a mensagem',
            $resposta->json('notificacoes.0.explicacao'),
        );

        $resposta->assertJsonPath('notificacoes.1.tipo_rotulo', 'Lembrete de dose prevista');
        $resposta->assertJsonPath('notificacoes.1.situacao_rotulo', 'Entregue');
    }

    public function test_o_historico_de_um_tutor_nao_alcanca_o_de_outro(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create();
        $bidu = $this->animal($outro, 'Bidu');

        Notificacao::factory()->create([
            'animal_id' => $bidu->id,
            'tutor_id' => $outro->id,
            'imunobiologico_id' => $this->v10()->id,
        ]);

        $this->actingAs($helena->user)
            ->getJson('/api/conta/notificacoes/enviadas')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('notificacoes', []);
    }

    public function test_o_destinatario_e_o_endereco_do_envio_e_nao_o_atual(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');

        Notificacao::factory()->falhou()->create([
            'animal_id' => $theo->id,
            'tutor_id' => $helena->id,
            'destinatario' => 'helena.antigo@example.com',
            'imunobiologico_id' => $this->v10()->id,
        ]);

        // RF06 — Helena corrigiu o endereço depois da falha. A linha continua
        // dizendo para onde a mensagem foi, e avisa que não é mais o da conta.
        $resposta = $this->actingAs($helena->user)
            ->getJson('/api/conta/notificacoes/enviadas')
            ->assertOk();

        $resposta->assertJsonPath('notificacoes.0.destinatario', 'hel•••@example.com');
        $resposta->assertJsonPath('notificacoes.0.endereco_anterior', true);
        $resposta->assertJsonPath('conta.email', 'helena.ramos@example.com');
    }

    public function test_o_lembrete_de_animal_transferido_continua_no_historico_de_quem_o_recebeu(): void
    {
        $helena = $this->helena();
        $novoTutor = Tutor::factory()->create();
        $theo = $this->animal($novoTutor, 'Théo');

        // RF21 — a mensagem foi para Helena quando o Théo ainda era dela.
        Notificacao::factory()->create([
            'animal_id' => $theo->id,
            'tutor_id' => $helena->id,
            'imunobiologico_id' => $this->v10()->id,
        ]);

        $resposta = $this->actingAs($helena->user)
            ->getJson('/api/conta/notificacoes/enviadas')
            ->assertOk();

        $resposta->assertJsonPath('total', 1);

        // A carteira é de quem é o tutor agora: a tela não oferece o caminho.
        $resposta->assertJsonPath('notificacoes.0.animal.do_tutor', false);

        // E o novo tutor não herda lembretes que nunca lhe foram enviados.
        $this->actingAs($novoTutor->user)
            ->getJson('/api/conta/notificacoes/enviadas')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_vacina_nao_identificada_vem_como_nula(): void
    {
        $helena = $this->helena();
        $theo = $this->animal($helena, 'Théo');

        Notificacao::factory()->semConfirmacao()->create([
            'animal_id' => $theo->id,
            'tutor_id' => $helena->id,
            'imunobiologico_id' => null,
        ]);

        $resposta = $this->actingAs($helena->user)
            ->getJson('/api/conta/notificacoes/enviadas')
            ->assertOk();

        $resposta->assertJsonPath('notificacoes.0.vacina', null);
        $resposta->assertJsonPath('notificacoes.0.situacao_rotulo', 'Sem confirmação');
        $resposta->assertJsonPath('notificacoes.0.endereco_anterior', false);
    }
}
