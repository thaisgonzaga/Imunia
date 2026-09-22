<?php

namespace Tests\Feature;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AtendimentoControllerTest extends TestCase
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

    private function theoDe(Tutor $tutor): Animal
    {
        return Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
    }

    public function test_o_detalhe_do_atendimento_exige_sessao(): void
    {
        $this->getJson('/api/animais/IM-7F3K-92QD/atendimentos/1')->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_alcanca_o_atendimento(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson('/api/animais/IM-7F3K-92QD/atendimentos/1')
            ->assertForbidden();
    }

    // RN12 — mesma resposta para código inexistente e animal de outro tutor.
    public function test_animal_de_outro_tutor_responde_404(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);
        $atendimento = Atendimento::factory()->create(['animal_id' => $animal->id]);

        $this->actingAs($helena->user)
            ->getJson("/api/animais/{$animal->codigo}/atendimentos/{$atendimento->id}")
            ->assertNotFound();
    }

    public function test_atendimento_de_outro_animal_do_mesmo_tutor_responde_404(): void
    {
        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $nina = Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);
        $atendimento = Atendimento::factory()->create(['animal_id' => $nina->id]);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}")
            ->assertNotFound();
    }

    // RF31 — o prontuário inteiro, com a autoria à vista e na ordem em que o
    // requisito enumera as seções.
    public function test_o_detalhe_traz_as_secoes_do_prontuario_e_a_autoria(): void
    {
        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $clinica = Prestador::factory()->create(['nome' => 'Clínica Vet Amigo']);
        $atendimento = Atendimento::factory()
            ->comRetorno()
            ->create(['animal_id' => $theo->id, 'prestador_id' => $clinica->id]);

        $resposta = $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('animal.nome', 'Théo');
        $resposta->assertJsonPath('atendimento.titulo', 'Dermatite');
        $resposta->assertJsonPath('atendimento.data', '2025-11-12');
        $resposta->assertJsonPath('atendimento.hora', '16:20');
        $resposta->assertJsonPath('atendimento.eh_retificacao', false);

        // As mesmas chaves de uma aplicação de vacina: o chip de procedência é
        // montado no cliente a partir delas.
        $resposta->assertJsonPath('atendimento.origem', 'profissional');
        $resposta->assertJsonPath('atendimento.aplicador.nome', 'Dr. Marcelo Andrade');
        $resposta->assertJsonPath('atendimento.aplicador.crmv', 'CRMV-MG 12345');
        $resposta->assertJsonPath('atendimento.aplicador.prestador', 'Clínica Vet Amigo');

        $resposta->assertJsonCount(6, 'atendimento.secoes');
        $this->assertSame(
            ['motivo', 'anamnese', 'exame_fisico', 'hipoteses_diagnosticas', 'diagnostico', 'conduta'],
            array_column($resposta->json('atendimento.secoes'), 'chave'),
        );
        $resposta->assertJsonPath('atendimento.secoes.1.rotulo', 'Anamnese');

        // RF34 — retorno programado com data prevista e finalidade descrita.
        $resposta->assertJsonPath('atendimento.retorno.em', '2025-11-15');
        $resposta->assertJsonPath('atendimento.retorno.finalidade', 'Reavaliação com o resultado do raspado.');

        // Sem retificação, os dois lados do encadeamento ficam nulos.
        $resposta->assertJsonPath('retificacao', null);
        $resposta->assertJsonPath('original', null);
    }

    public function test_secao_sem_conteudo_nao_e_devolvida(): void
    {
        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $atendimento = Atendimento::factory()->create([
            'animal_id' => $theo->id,
            'diagnostico' => null,
        ]);

        $resposta = $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}");

        $resposta->assertOk();
        $resposta->assertJsonCount(5, 'atendimento.secoes');
        $this->assertNotContains('diagnostico', array_column($resposta->json('atendimento.secoes'), 'chave'));
        $resposta->assertJsonPath('atendimento.retorno', null);
    }

    // RF32c — o anexo sai com o endereço da rota autorizada, e o caminho no
    // armazenamento não aparece em resposta alguma.
    public function test_os_anexos_saem_pela_rota_autorizada_e_nunca_pelo_caminho_do_arquivo(): void
    {
        Storage::fake('local');

        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $atendimento = Atendimento::factory()->create(['animal_id' => $theo->id]);

        $anexo = AnexoAtendimento::factory()->create([
            'atendimento_id' => $atendimento->id,
            'descricao' => 'Raspado cutâneo',
            'caminho' => 'anexos/raspado.png',
        ]);
        Storage::disk('local')->put('anexos/raspado.png', 'conteudo-de-teste');

        $resposta = $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('atendimento.anexos.0.descricao', 'Raspado cutâneo');
        $resposta->assertJsonPath('atendimento.anexos.0.tipo', 'imagem');
        $resposta->assertJsonPath('atendimento.anexos.0.exame_em', '2025-11-12');
        $resposta->assertJsonPath('atendimento.anexos.0.disponivel', true);
        $resposta->assertJsonPath(
            'atendimento.anexos.0.url',
            "/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/anexos/{$anexo->id}",
        );
        $resposta->assertJsonMissing(['caminho' => 'anexos/raspado.png']);
    }

    // O prontuário continua legível quando um anexo não responde: a tela avisa
    // naquele item, e não no lugar do atendimento inteiro.
    public function test_anexo_sem_arquivo_no_disco_e_marcado_como_indisponivel(): void
    {
        Storage::fake('local');

        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $atendimento = Atendimento::factory()->create(['animal_id' => $theo->id]);
        $anexo = AnexoAtendimento::factory()->create([
            'atendimento_id' => $atendimento->id,
            'caminho' => 'anexos/perdido.png',
        ]);

        $resposta = $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('atendimento.anexos.0.disponivel', false);

        $this->actingAs($tutor->user)
            ->get("/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/anexos/{$anexo->id}")
            ->assertNotFound();
    }

    public function test_a_rota_do_anexo_serve_o_arquivo_com_o_tipo_declarado(): void
    {
        Storage::fake('local');

        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $atendimento = Atendimento::factory()->create(['animal_id' => $theo->id]);
        $anexo = AnexoAtendimento::factory()->documento()->create([
            'atendimento_id' => $atendimento->id,
            'caminho' => 'anexos/laudo.pdf',
        ]);
        Storage::disk('local')->put('anexos/laudo.pdf', '%PDF-1.4 laudo');

        $resposta = $this->actingAs($tutor->user)
            ->get("/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/anexos/{$anexo->id}");

        $resposta->assertOk();
        $resposta->assertHeader('Content-Type', 'application/pdf');
        $resposta->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('%PDF-1.4 laudo', $resposta->streamedContent());
    }

    public function test_a_rota_do_anexo_exige_sessao_e_respeita_o_ambito_do_tutor(): void
    {
        Storage::fake('local');

        $helena = $this->helena();
        $theo = $this->theoDe($helena);
        $atendimento = Atendimento::factory()->create(['animal_id' => $theo->id]);
        $anexo = AnexoAtendimento::factory()->create(['atendimento_id' => $atendimento->id]);

        $caminho = "/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/anexos/{$anexo->id}";

        $this->getJson($caminho)->assertUnauthorized();

        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $this->actingAs($outro->user)->getJson($caminho)->assertNotFound();
    }

    public function test_anexo_de_outro_atendimento_responde_404(): void
    {
        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $atendimento = Atendimento::factory()->create(['animal_id' => $theo->id]);
        $outroAtendimento = Atendimento::factory()->create(['animal_id' => $theo->id]);
        $anexo = AnexoAtendimento::factory()->create(['atendimento_id' => $outroAtendimento->id]);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/atendimentos/{$atendimento->id}/anexos/{$anexo->id}")
            ->assertNotFound();
    }

    // RF33 — o original permanece íntegro e sinalizado como retificado, e a
    // comparação diz exatamente o que mudou.
    public function test_o_registro_retificado_aponta_para_a_retificacao_com_os_campos_alterados(): void
    {
        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $original = Atendimento::factory()->create(['animal_id' => $theo->id]);
        // A data que a tela exibe é a da **correção**, e não a do atendimento:
        // a retificação conserva o `atendido_em` do original, porque a consulta
        // aconteceu quando aconteceu (V09).
        $retificacao = Atendimento::factory()
            ->retificando($original)
            ->create([
                'created_at' => '2025-11-15 09:40:00',
                'diagnostico' => 'Dermatite por Sarcoptes scabiei, confirmada pelo raspado cutâneo.',
            ]);

        $resposta = $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/atendimentos/{$original->id}");

        $resposta->assertOk();
        // O conteúdo do original continua o que era: nada nele foi tocado.
        $resposta->assertJsonPath(
            'atendimento.secoes.4.texto',
            'Aguardando resultado do raspado cutâneo coletado nesta consulta.',
        );
        $resposta->assertJsonPath('retificacao.id', $retificacao->id);
        $resposta->assertJsonPath('retificacao.em', '2025-11-15');
        $resposta->assertJsonPath(
            'retificacao.motivo',
            'Resultado do exame recebido três dias depois da consulta.',
        );
        $resposta->assertJsonPath('retificacao.responsavel.nome', 'Dr. Marcelo Andrade');
        $resposta->assertJsonPath(
            'retificacao.url',
            "/api/animais/{$theo->codigo}/atendimentos/{$retificacao->id}",
        );

        // Só o campo que mudou entra na comparação.
        $resposta->assertJsonCount(1, 'retificacao.campos_alterados');
        $resposta->assertJsonPath('retificacao.campos_alterados.0.chave', 'diagnostico');
        $resposta->assertJsonPath('retificacao.campos_alterados.0.rotulo', 'Diagnóstico');
        $resposta->assertJsonPath(
            'retificacao.campos_alterados.0.antes',
            'Aguardando resultado do raspado cutâneo coletado nesta consulta.',
        );
        $resposta->assertJsonPath(
            'retificacao.campos_alterados.0.depois',
            'Dermatite por Sarcoptes scabiei, confirmada pelo raspado cutâneo.',
        );
        $resposta->assertJsonPath('original', null);
    }

    // RF33b — o encadeamento é navegável nos dois sentidos.
    public function test_a_retificacao_aponta_de_volta_para_o_registro_original(): void
    {
        $tutor = $this->helena();
        $theo = $this->theoDe($tutor);
        $original = Atendimento::factory()->create([
            'animal_id' => $theo->id,
            'created_at' => '2025-11-12 16:20:00',
        ]);
        $retificacao = Atendimento::factory()
            ->retificando($original)
            ->create([
                'created_at' => '2025-11-15 09:40:00',
                'diagnostico' => 'Dermatite por Sarcoptes scabiei, confirmada pelo raspado cutâneo.',
            ]);

        $resposta = $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$theo->codigo}/atendimentos/{$retificacao->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('atendimento.eh_retificacao', true);
        $resposta->assertJsonPath('original.id', $original->id);
        $resposta->assertJsonPath('original.em', '2025-11-12');
        $resposta->assertJsonPath('original.titulo', 'Dermatite');
        $resposta->assertJsonPath(
            'original.url',
            "/api/animais/{$theo->codigo}/atendimentos/{$original->id}",
        );
        // A comparação viaja também aqui: quem lê a correção precisa poder ver o
        // que estava escrito antes, sem sair da tela.
        $resposta->assertJsonPath('original.campos_alterados.0.chave', 'diagnostico');
        $resposta->assertJsonPath('retificacao', null);
    }
}
