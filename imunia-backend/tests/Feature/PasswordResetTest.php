<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * P05 e P06 — RF03.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        return User::factory()->create([
            'email' => 'helena.ramos@example.com',
            'password' => Hash::make('Segredo123'),
        ]);
    }

    public function test_pedido_envia_a_ligacao_de_redefinicao(): void
    {
        Notification::fake();
        $usuario = $this->usuario();

        $this->postJson('/api/senha/recuperar', ['email' => $usuario->email])
            ->assertStatus(202);

        Notification::assertSentTo($usuario, ResetPassword::class);
    }

    /**
     * RF03 — a confirmação é a mesma exista ou não a conta.
     */
    public function test_confirmacao_e_identica_para_endereco_sem_conta(): void
    {
        Notification::fake();
        $this->usuario();

        $comConta = $this->postJson('/api/senha/recuperar', ['email' => 'helena.ramos@example.com']);
        $semConta = $this->postJson('/api/senha/recuperar', ['email' => 'ninguem@example.com']);

        $semConta->assertStatus(202);
        $this->assertSame($comConta->json('message'), $semConta->json('message'));
        Notification::assertCount(1);
    }

    /**
     * RNF11.
     */
    public function test_pedidos_repetidos_sao_limitados_por_taxa(): void
    {
        Notification::fake();
        $this->usuario();

        foreach (range(1, 3) as $ignorado) {
            $this->postJson('/api/senha/recuperar', ['email' => 'helena.ramos@example.com'])
                ->assertStatus(202);
        }

        $limitado = $this->postJson('/api/senha/recuperar', ['email' => 'helena.ramos@example.com']);

        $limitado->assertStatus(429);
        $this->assertGreaterThan(0, $limitado->json('segundos_restantes'));
    }

    public function test_ligacao_valida_e_reconhecida_antes_do_formulario(): void
    {
        $usuario = $this->usuario();
        $token = Password::createToken($usuario);

        $this->getJson("/api/senha/redefinir/{$token}?email=".urlencode($usuario->email))
            ->assertOk()
            ->assertJsonPath('situacao', 'valida');
    }

    public function test_ligacao_vencida_e_reconhecida_como_expirada(): void
    {
        $usuario = $this->usuario();
        $token = Password::createToken($usuario);

        Carbon::setTestNow(Carbon::now()->addMinutes(31));

        $this->getJson("/api/senha/redefinir/{$token}?email=".urlencode($usuario->email))
            ->assertOk()
            ->assertJsonPath('situacao', 'expirada');

        Carbon::setTestNow();
    }

    public function test_ligacao_ja_consumida_e_reconhecida(): void
    {
        $usuario = $this->usuario();
        $token = Password::createToken($usuario);

        $this->postJson('/api/senha/redefinir', [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'NovaSenha123',
            'password_confirmation' => 'NovaSenha123',
        ])->assertOk();

        $this->getJson("/api/senha/redefinir/{$token}?email=".urlencode($usuario->email))
            ->assertOk()
            ->assertJsonPath('situacao', 'utilizada');
    }

    public function test_redefinicao_troca_a_senha_e_permite_entrar(): void
    {
        $usuario = $this->usuario();
        $token = Password::createToken($usuario);

        $this->postJson('/api/senha/redefinir', [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'NovaSenha123',
            'password_confirmation' => 'NovaSenha123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NovaSenha123', $usuario->fresh()->password));

        $this->postJson('/api/sessao', [
            'email' => $usuario->email,
            'password' => 'NovaSenha123',
        ])->assertOk();
    }

    /**
     * RF03b — a redefinição encerra as demais sessões ativas do usuário.
     */
    public function test_redefinicao_encerra_as_demais_sessoes(): void
    {
        config(['session.driver' => 'database']);

        $usuario = $this->usuario();
        $token = Password::createToken($usuario);

        DB::table('sessions')->insert([
            'id' => 'sessao-de-outro-aparelho',
            'user_id' => $usuario->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'aparelho antigo',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->postJson('/api/senha/redefinir', [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'NovaSenha123',
            'password_confirmation' => 'NovaSenha123',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-de-outro-aparelho']);
    }

    /**
     * RN04 — a nova senha observa a mesma política do cadastro.
     */
    public function test_senha_nova_obedece_a_politica(): void
    {
        $usuario = $this->usuario();
        $token = Password::createToken($usuario);

        $this->postJson('/api/senha/redefinir', [
            'token' => $token,
            'email' => $usuario->email,
            'password' => 'curta',
            'password_confirmation' => 'curta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_ligacao_invalida_nao_troca_a_senha(): void
    {
        $usuario = $this->usuario();

        $this->postJson('/api/senha/redefinir', [
            'token' => 'token-inventado',
            'email' => $usuario->email,
            'password' => 'NovaSenha123',
            'password_confirmation' => 'NovaSenha123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('Segredo123', $usuario->fresh()->password));
    }
}
