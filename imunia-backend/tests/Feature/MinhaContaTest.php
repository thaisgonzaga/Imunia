<?php

namespace Tests\Feature;

use App\Models\Tutor;
use App\Models\User;
use App\Notifications\ConfirmacaoDeEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * T18 — minha conta (RF04, RF06).
 */
class MinhaContaTest extends TestCase
{
    use RefreshDatabase;

    private function tutora(array $atributos = []): User
    {
        $usuario = User::factory()->create(['name' => 'Helena Ramos', ...$atributos]);
        Tutor::factory()->create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
            'cpf' => '52998224725',
        ]);

        return $usuario;
    }

    public function test_a_conta_exige_sessao(): void
    {
        $this->getJson('/api/conta')->assertUnauthorized();
        $this->postJson('/api/conta', [])->assertUnauthorized();
        $this->postJson('/api/conta/senha', [])->assertUnauthorized();
    }

    public function test_a_tela_reune_os_dados_pessoais_do_titular(): void
    {
        $usuario = $this->tutora(['email' => 'helena.ramos@example.com']);

        $this->actingAs($usuario)->getJson('/api/conta')
            ->assertOk()
            ->assertJsonPath('conta.nome', 'Helena Ramos')
            ->assertJsonPath('conta.email', 'helena.ramos@example.com')
            ->assertJsonPath('conta.email_verificado', true)
            ->assertJsonPath('conta.cpf', '52998224725')
            ->assertJsonPath('conta.papeis', ['tutor']);
    }

    /**
     * Quem não é tutor não tem CPF no Imunia, e a tela precisa saber disso para
     * não exibir um campo vazio ao veterinário.
     */
    public function test_quem_nao_e_tutor_nao_tem_cpf(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/conta')
            ->assertOk()
            ->assertJsonPath('conta.cpf', null);
    }

    public function test_o_nome_alterado_acompanha_o_cadastro_de_tutor(): void
    {
        $usuario = $this->tutora(['email' => 'helena.ramos@example.com']);

        $this->actingAs($usuario)->postJson('/api/conta', [
            'nome' => 'Helena Ramos Almeida',
            'email' => 'helena.ramos@example.com',
        ])->assertOk()->assertJsonPath('conta.nome', 'Helena Ramos Almeida');

        $this->assertSame('Helena Ramos Almeida', $usuario->fresh()->name);
        $this->assertSame('Helena Ramos Almeida', $usuario->tutor->fresh()->nome);
    }

    /**
     * RF06a — a troca de endereço reinicia o ciclo de verificação de RF05.
     */
    public function test_trocar_o_email_reinicia_a_verificacao(): void
    {
        Notification::fake();

        $usuario = $this->tutora(['email' => 'helena.ramos@example.com']);

        $this->actingAs($usuario)->postJson('/api/conta', [
            'nome' => 'Helena Ramos',
            'email' => 'helena@novoprovedor.com',
        ])
            ->assertOk()
            ->assertJsonPath('conta.email_verificado', false)
            ->assertJsonCount(1, 'avisos');

        $this->assertNull($usuario->fresh()->email_verified_at);
        Notification::assertSentTo($usuario, ConfirmacaoDeEmail::class);
    }

    public function test_salvar_sem_trocar_o_email_preserva_a_verificacao(): void
    {
        Notification::fake();

        $usuario = $this->tutora(['email' => 'helena.ramos@example.com']);

        $this->actingAs($usuario)->postJson('/api/conta', [
            'nome' => 'Helena R. Ramos',
            'email' => 'helena.ramos@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('conta.email_verificado', true)
            ->assertJsonCount(0, 'avisos');

        Notification::assertNothingSent();
    }

    public function test_o_email_de_outra_conta_e_recusado(): void
    {
        User::factory()->create(['email' => 'ocupado@example.com']);
        $usuario = $this->tutora();

        $this->actingAs($usuario)->postJson('/api/conta', [
            'nome' => 'Helena Ramos',
            'email' => 'ocupado@example.com',
        ])->assertJsonValidationErrors('email');
    }

    /**
     * RF04a — a senha vigente incorreta impede a alteração.
     */
    public function test_a_senha_atual_incorreta_impede_a_troca(): void
    {
        $usuario = User::factory()->create(['password' => Hash::make('SenhaAntiga1')]);

        $this->actingAs($usuario)->postJson('/api/conta/senha', [
            'senha_atual' => 'ChutePerdido1',
            'password' => 'SenhaNovinha1',
            'password_confirmation' => 'SenhaNovinha1',
        ])->assertJsonValidationErrors('senha_atual');

        $this->assertTrue(Hash::check('SenhaAntiga1', $usuario->fresh()->password));
    }

    /**
     * RF04b — a nova senha observa a política de RN04.
     */
    public function test_a_nova_senha_observa_a_politica(): void
    {
        $usuario = User::factory()->create(['password' => Hash::make('SenhaAntiga1')]);

        $this->actingAs($usuario)->postJson('/api/conta/senha', [
            'senha_atual' => 'SenhaAntiga1',
            'password' => 'curta',
            'password_confirmation' => 'curta',
        ])->assertJsonValidationErrors('password');
    }

    public function test_a_troca_de_senha_vale_para_a_proxima_entrada(): void
    {
        $usuario = User::factory()->create([
            'email' => 'helena.ramos@example.com',
            'password' => Hash::make('SenhaAntiga1'),
        ]);

        $this->actingAs($usuario)->postJson('/api/conta/senha', [
            'senha_atual' => 'SenhaAntiga1',
            'password' => 'SenhaNovinha1',
            'password_confirmation' => 'SenhaNovinha1',
        ])->assertOk();

        $this->assertTrue(Hash::check('SenhaNovinha1', $usuario->fresh()->password));
    }
}
