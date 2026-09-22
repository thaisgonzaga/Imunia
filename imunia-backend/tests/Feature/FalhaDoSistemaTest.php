<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * E03 — o que o servidor responde quando a falha não é prevista por ninguém.
 * O contrato é curto: status 500, uma frase sem jargão (RNF17) e um número de
 * protocolo a informar ao suporte.
 */
class FalhaDoSistemaTest extends TestCase
{
    private function rotaQueQuebra(): void
    {
        Route::middleware('api')->get('/api/_teste/falha', function (): void {
            throw new RuntimeException('Conexão perdida com o serviço de armazenamento.');
        });
    }

    public function test_a_falha_inesperada_responde_com_frase_sem_jargao_e_identificador(): void
    {
        config(['app.debug' => false]);
        $this->rotaQueQuebra();

        $resposta = $this->getJson('/api/_teste/falha');

        $resposta->assertStatus(500)
            ->assertJson([
                'message' => 'Não conseguimos concluir a operação. Tente novamente em instantes.',
            ]);

        $this->assertMatchesRegularExpression(
            '/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}$/',
            $resposta->json('ocorrencia'),
        );
    }

    public function test_em_producao_a_resposta_nao_diz_o_que_quebrou(): void
    {
        config(['app.debug' => false]);
        $this->rotaQueQuebra();

        $resposta = $this->getJson('/api/_teste/falha');

        // Nem a classe da exceção, nem o arquivo, nem a mensagem original: a
        // tela de exceção comunica impedimento, não implementação.
        $resposta->assertJsonMissingPath('depuracao');
        $this->assertStringNotContainsString('RuntimeException', $resposta->getContent());
        $this->assertStringNotContainsString('armazenamento', $resposta->getContent());
    }

    public function test_fora_de_producao_o_detalhe_tecnico_continua_na_resposta(): void
    {
        config(['app.debug' => true]);
        $this->rotaQueQuebra();

        $this->getJson('/api/_teste/falha')
            ->assertStatus(500)
            ->assertJsonPath('depuracao.excecao', RuntimeException::class)
            ->assertJsonPath('depuracao.mensagem', 'Conexão perdida com o serviço de armazenamento.');
    }

    public function test_cada_requisicao_recebe_um_identificador_proprio(): void
    {
        config(['app.debug' => false]);
        $this->rotaQueQuebra();

        $primeira = $this->getJson('/api/_teste/falha')->json('ocorrencia');
        $segunda = $this->getJson('/api/_teste/falha')->json('ocorrencia');

        $this->assertNotSame($primeira, $segunda);
    }

    public function test_a_rota_inexistente_continua_respondendo_nao_encontrado(): void
    {
        // E02 é do cliente: a rota que não existe não é falha do sistema, e
        // trocar o 404 por 500 esconderia o engano de quem chamou.
        $this->getJson('/api/nao-existe')->assertNotFound();
    }

    public function test_a_falta_de_sessao_e_401_mesmo_sem_anunciar_json(): void
    {
        config(['app.debug' => false]);

        // O SPA sempre pede JSON, mas quem abre um endereço da API direto no
        // navegador não pede — e falta de sessão não é falha do sistema.
        $this->get('/api/sessao')
            ->assertUnauthorized()
            ->assertJsonMissingPath('ocorrencia');
    }

    public function test_a_recusa_por_papel_continua_com_a_mensagem_do_seu_ponto(): void
    {
        // E01 vem de 403 com texto próprio de cada área. O tratador de E03 não
        // pode uniformizar essas respostas: elas dizem ao SPA qual das duas
        // telas de exceção desenhar.
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson('/api/tutor/painel')
            ->assertForbidden()
            ->assertJson(['message' => 'Esta área é do ambiente do tutor.'])
            ->assertJsonMissingPath('ocorrencia');
    }
}
