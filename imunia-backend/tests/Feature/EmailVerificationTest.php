<?php

namespace Tests\Feature;

use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Notifications\ConfirmacaoDeEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * P08 — RF05.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Captura o token em claro, que só existe no momento da emissão.
     */
    private function emitirPara(User $usuario): string
    {
        return EmailVerificationToken::emitirPara($usuario);
    }

    /**
     * RF12c — o autocadastro dispara imediatamente a verificação.
     */
    public function test_autocadastro_de_tutor_dispara_a_confirmacao(): void
    {
        Notification::fake();

        $this->postJson('/api/tutores', [
            'nome' => 'Helena Ramos',
            'cpf' => '52998224725',
            'email' => 'helena.ramos@example.com',
            'password' => 'Segredo123',
            'password_confirmation' => 'Segredo123',
            'aceite_termos' => true,
        ])->assertCreated();

        $usuario = User::firstWhere('email', 'helena.ramos@example.com');

        $this->assertNull($usuario->email_verified_at);
        Notification::assertSentTo($usuario, ConfirmacaoDeEmail::class);
    }

    public function test_ligacao_valida_confirma_o_endereco(): void
    {
        $usuario = User::factory()->unverified()->create();
        $token = $this->emitirPara($usuario);

        $this->postJson("/api/email/verificar/{$token}")
            ->assertOk()
            ->assertJsonPath('situacao', 'confirmado');

        $this->assertNotNull($usuario->fresh()->email_verified_at);
    }

    public function test_ligacao_e_de_uso_unico_e_a_segunda_visita_apenas_confirma(): void
    {
        $usuario = User::factory()->unverified()->create();
        $token = $this->emitirPara($usuario);

        $this->postJson("/api/email/verificar/{$token}")->assertOk();

        $this->postJson("/api/email/verificar/{$token}")
            ->assertOk()
            ->assertJsonPath('situacao', 'confirmado');
    }

    public function test_ligacao_vencida_nao_confirma(): void
    {
        $usuario = User::factory()->unverified()->create();
        $token = $this->emitirPara($usuario);

        Carbon::setTestNow(Carbon::now()->addHours(EmailVerificationToken::VALIDADE_EM_HORAS + 1));

        $this->postJson("/api/email/verificar/{$token}")
            ->assertStatus(410)
            ->assertJsonPath('situacao', 'expirada');

        Carbon::setTestNow();

        $this->assertNull($usuario->fresh()->email_verified_at);
    }

    public function test_ligacao_inventada_nao_confirma(): void
    {
        $this->postJson('/api/email/verificar/token-inventado')
            ->assertStatus(404)
            ->assertJsonPath('situacao', 'invalida');
    }

    /**
     * RF06a — a troca de endereço reinicia o ciclo, e a ligação antiga morre.
     */
    public function test_ligacao_antiga_nao_confirma_endereco_novo(): void
    {
        $usuario = User::factory()->unverified()->create(['email' => 'antigo@example.com']);
        $token = $this->emitirPara($usuario);

        $usuario->forceFill(['email' => 'novo@example.com'])->save();

        $this->postJson("/api/email/verificar/{$token}")->assertStatus(404);

        $this->assertNull($usuario->fresh()->email_verified_at);
    }

    public function test_reenvio_emite_nova_ligacao(): void
    {
        Notification::fake();
        $usuario = User::factory()->unverified()->create(['email' => 'helena.ramos@example.com']);

        $this->postJson('/api/email/reenviar', ['email' => $usuario->email])
            ->assertStatus(202)
            ->assertJsonPath('email_mascarado', 'hel•••@example.com');

        Notification::assertSentTo($usuario, ConfirmacaoDeEmail::class);
    }

    public function test_reenvio_responde_igual_para_endereco_sem_conta(): void
    {
        Notification::fake();
        User::factory()->unverified()->create(['email' => 'helena.ramos@example.com']);

        $comConta = $this->postJson('/api/email/reenviar', ['email' => 'helena.ramos@example.com']);
        $semConta = $this->postJson('/api/email/reenviar', ['email' => 'ninguem@example.com']);

        $semConta->assertStatus(202);
        $this->assertSame($comConta->json('message'), $semConta->json('message'));
        Notification::assertCount(1);
    }

    public function test_reenvio_seguido_e_recusado_com_o_tempo_restante(): void
    {
        Notification::fake();
        $usuario = User::factory()->unverified()->create();

        $this->postJson('/api/email/reenviar', ['email' => $usuario->email])->assertStatus(202);

        $limitado = $this->postJson('/api/email/reenviar', ['email' => $usuario->email]);

        $limitado->assertStatus(429);
        $this->assertGreaterThan(0, $limitado->json('segundos_restantes'));
    }

    public function test_emissao_descarta_a_ligacao_anterior(): void
    {
        $usuario = User::factory()->unverified()->create();
        $primeiro = $this->emitirPara($usuario);
        $segundo = $this->emitirPara($usuario);

        $this->postJson("/api/email/verificar/{$primeiro}")->assertStatus(404);
        $this->postJson("/api/email/verificar/{$segundo}")->assertOk();
    }
}
