<?php

namespace Tests\Feature;

use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P02 — RF01 e RF02.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function tutor(array $overrides = []): User
    {
        $usuario = User::factory()->create(array_merge([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
            'password' => Hash::make('Segredo123'),
        ], $overrides));

        Tutor::create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
            'cpf' => '52998224725',
        ]);

        return $usuario;
    }

    public function test_credenciais_validas_estabelecem_sessao(): void
    {
        $usuario = $this->tutor();

        $response = $this->postJson('/api/sessao', [
            'email' => 'helena.ramos@example.com',
            'password' => 'Segredo123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('usuario.nome', 'Helena Ramos');
        $response->assertJsonPath('usuario.papeis', ['tutor']);
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_tutor_e_encaminhado_ao_painel_do_seu_papel(): void
    {
        $this->tutor();

        $response = $this->postJson('/api/sessao', [
            'email' => 'helena.ramos@example.com',
            'password' => 'Segredo123',
        ]);

        $response->assertJsonPath('usuario.rota_inicial', '/inicio');
    }

    public function test_veterinario_e_encaminhado_ao_ambiente_de_registro(): void
    {
        $usuario = User::factory()->create([
            'email' => 'marcelo@example.com',
            'password' => Hash::make('Segredo123'),
        ]);

        Prestador::factory()->create()->usuarios()->attach($usuario->id, [
            'papel' => 'veterinario',
            'crmv' => '12345',
            'crmv_uf' => 'MG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/sessao', [
            'email' => 'marcelo@example.com',
            'password' => 'Segredo123',
        ]);

        $response->assertJsonPath('usuario.rota_inicial', '/clinica/painel');
    }

    /**
     * RF01b — a resposta não pode distinguir os dois casos, sob pena de a tela
     * virar consulta a quais endereços possuem conta.
     */
    public function test_mensagem_e_identica_para_senha_errada_e_conta_inexistente(): void
    {
        $this->tutor();

        $senhaErrada = $this->postJson('/api/sessao', [
            'email' => 'helena.ramos@example.com',
            'password' => 'OutraCoisa123',
        ]);

        $contaInexistente = $this->postJson('/api/sessao', [
            'email' => 'ninguem@example.com',
            'password' => 'OutraCoisa123',
        ]);

        $senhaErrada->assertStatus(422);
        $contaInexistente->assertStatus(422);
        $this->assertSame(
            $senhaErrada->json('errors.email'),
            $contaInexistente->json('errors.email'),
        );
        $this->assertSame(['E-mail ou senha incorretos.'], $senhaErrada->json('errors.email'));
        $this->assertGuest();
    }

    /**
     * RN03 e RF01d.
     */
    public function test_tentativas_seguidas_levam_a_bloqueio_temporario(): void
    {
        $this->tutor();

        foreach (range(1, 5) as $ignorado) {
            $this->postJson('/api/sessao', [
                'email' => 'helena.ramos@example.com',
                'password' => 'ErradaDeNovo1',
            ])->assertStatus(422);
        }

        $bloqueado = $this->postJson('/api/sessao', [
            'email' => 'helena.ramos@example.com',
            'password' => 'ErradaDeNovo1',
        ]);

        $bloqueado->assertStatus(429);
        $this->assertGreaterThan(0, $bloqueado->json('segundos_restantes'));

        // Nem a senha correta passa enquanto durar a pausa.
        $this->postJson('/api/sessao', [
            'email' => 'helena.ramos@example.com',
            'password' => 'Segredo123',
        ])->assertStatus(429);
    }

    public function test_bloqueio_de_uma_conta_nao_alcanca_outra(): void
    {
        $this->tutor();
        User::factory()->create([
            'email' => 'marcelo@example.com',
            'password' => Hash::make('Segredo123'),
        ]);

        foreach (range(1, 5) as $ignorado) {
            $this->postJson('/api/sessao', [
                'email' => 'helena.ramos@example.com',
                'password' => 'ErradaDeNovo1',
            ]);
        }

        $this->postJson('/api/sessao', [
            'email' => 'marcelo@example.com',
            'password' => 'Segredo123',
        ])->assertOk();
    }

    public function test_sessao_corrente_descreve_o_usuario_autenticado(): void
    {
        $usuario = $this->tutor(['email_verified_at' => null]);

        $response = $this->actingAs($usuario)->getJson('/api/sessao');

        $response->assertOk();
        $response->assertJsonPath('usuario.email_verificado', false);
    }

    public function test_visitante_nao_alcanca_a_sessao_corrente(): void
    {
        $this->getJson('/api/sessao')->assertStatus(401);
    }

    /**
     * RF02a.
     */
    public function test_encerramento_invalida_a_sessao(): void
    {
        $this->tutor();

        $this->postJson('/api/sessao', [
            'email' => 'helena.ramos@example.com',
            'password' => 'Segredo123',
        ])->assertOk();

        $this->deleteJson('/api/sessao')->assertOk();

        // A verificação é sobre o guard de sessão, e não sobre uma requisição
        // seguinte: dentro de um mesmo teste a aplicação não é reconstruída, e
        // o guard do Sanctum ainda traria em memória o usuário já resolvido.
        $this->assertGuest('web');
    }
}
