<?php

namespace Tests\Feature;

use App\Models\Convite;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\ConviteDeAtivacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P07 — RF09 e RF14.
 */
class ConviteAtivacaoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reproduz o estado deixado pelo cadastro do tutor no atendimento (RF12):
     * a conta existe, sem senha utilizável e sem ativação, à espera do convite.
     *
     * @return array{0: Convite, 1: string, 2: User}
     */
    private function conviteDeTutor(): array
    {
        $prestador = Prestador::factory()->create();

        $usuario = User::factory()->unverified()->create([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
            // Senha aleatória e nunca comunicada: só o convite dá acesso.
            'password' => Hash::make(Str::random(40)),
            'ativado_em' => null,
        ]);

        Tutor::create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
            'cpf' => '52998224725',
        ]);

        [$convite, $token] = Convite::emitir($usuario, $prestador, 'tutor');

        return [$convite, $token, $usuario];
    }

    /**
     * Reproduz o estado deixado pelo convite de equipe (RF09): o vínculo com o
     * prestador já existe, com CRMV informado pelo administrador.
     *
     * @return array{0: Convite, 1: string, 2: User}
     */
    private function conviteDeVeterinario(): array
    {
        $prestador = Prestador::factory()->create();

        $administrador = User::factory()->create(['name' => 'Ana Lúcia Ferraz']);
        $prestador->usuarios()->attach($administrador->id, [
            'papel' => 'admin_prestador',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $convidado = User::factory()->unverified()->create([
            'name' => 'Marcelo Andrade',
            'email' => 'marcelo@example.com',
            'ativado_em' => null,
        ]);
        $prestador->usuarios()->attach($convidado->id, [
            'papel' => 'veterinario',
            'crmv' => '12345',
            'crmv_uf' => 'MG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$convite, $token] = Convite::emitir($convidado, $prestador, 'veterinario', $administrador);

        return [$convite, $token, $convidado];
    }

    public function test_convite_de_tutor_identifica_quem_convidou(): void
    {
        [, $token] = $this->conviteDeTutor();

        $response = $this->getJson("/api/convites/{$token}");

        $response->assertOk();
        $response->assertJsonPath('situacao', 'valido');
        $response->assertJsonPath('convite.tipo', 'tutor');
        $response->assertJsonPath('convite.prestador.nome', 'Clínica Vet Amigo');
        $response->assertJsonPath('convite.prestador.municipio', 'Viçosa');
        $response->assertJsonPath('convite.email', 'helena.ramos@example.com');
    }

    /**
     * RF09 — o CRMV informado pelo administrador aparece para conferência.
     */
    public function test_convite_de_veterinario_exibe_crmv_para_conferencia(): void
    {
        [, $token] = $this->conviteDeVeterinario();

        $response = $this->getJson("/api/convites/{$token}");

        $response->assertOk();
        $response->assertJsonPath('convite.tipo', 'veterinario');
        $response->assertJsonPath('convite.convidante', 'Ana Lúcia Ferraz');
        $response->assertJsonPath('convite.crmv', '12345');
        $response->assertJsonPath('convite.crmv_uf', 'MG');
    }

    public function test_aceite_define_a_senha_e_ativa_a_conta(): void
    {
        [$convite, $token, $usuario] = $this->conviteDeTutor();

        $response = $this->postJson("/api/convites/{$token}", [
            'password' => 'Segredo123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('usuario.rota_inicial', '/inicio');

        $usuario->refresh();
        $this->assertTrue(Hash::check('Segredo123', $usuario->password));
        $this->assertNotNull($usuario->ativado_em);
        $this->assertNotNull($convite->fresh()->aceito_em);
    }

    /**
     * RF14c — a ativação verifica o endereço e dispensa RF05.
     */
    public function test_aceite_confirma_o_endereco_automaticamente(): void
    {
        [, $token, $usuario] = $this->conviteDeTutor();

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])->assertOk();

        $this->assertNotNull($usuario->fresh()->email_verified_at);
    }

    public function test_aceite_ja_deixa_a_sessao_estabelecida(): void
    {
        [, $token, $usuario] = $this->conviteDeTutor();

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])->assertOk();

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_veterinario_ativado_cai_no_ambiente_de_registro(): void
    {
        [, $token] = $this->conviteDeVeterinario();

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])
            ->assertOk()
            ->assertJsonPath('usuario.rota_inicial', '/clinica/painel')
            ->assertJsonPath('usuario.papeis', ['veterinario']);
    }

    public function test_convite_vencido_e_recusado(): void
    {
        [, $token] = $this->conviteDeTutor();

        Carbon::setTestNow(Carbon::now()->addDays(Convite::VALIDADE_EM_DIAS + 1));

        $this->getJson("/api/convites/{$token}")
            ->assertStatus(410)
            ->assertJsonPath('situacao', 'expirado');

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])
            ->assertStatus(410);

        Carbon::setTestNow();
    }

    public function test_convite_ja_aceito_nao_serve_de_novo(): void
    {
        [, $token] = $this->conviteDeTutor();

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])->assertOk();

        $this->getJson("/api/convites/{$token}")
            ->assertStatus(409)
            ->assertJsonPath('situacao', 'aceito');

        $this->postJson("/api/convites/{$token}", ['password' => 'OutraSenha123'])
            ->assertStatus(410);
    }

    /**
     * RF14a — o convite expira e pode ser reenviado; o token antigo morre no
     * mesmo ato.
     */
    public function test_convite_vencido_pode_ser_reenviado(): void
    {
        Notification::fake();
        [$convite, $token, $usuario] = $this->conviteDeTutor();

        Carbon::setTestNow(Carbon::now()->addDays(Convite::VALIDADE_EM_DIAS + 1));

        $this->postJson("/api/convites/{$token}/reenviar")->assertStatus(202);

        Carbon::setTestNow();

        Notification::assertSentTo($usuario, ConviteDeAtivacao::class);
        $this->assertFalse($convite->fresh()->expirou());
        $this->getJson("/api/convites/{$token}")->assertStatus(404);
    }

    public function test_convite_ja_aceito_nao_e_reenviado(): void
    {
        Notification::fake();
        [, $token] = $this->conviteDeTutor();

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])->assertOk();

        $this->postJson("/api/convites/{$token}/reenviar")->assertStatus(410);

        Notification::assertNothingSent();
    }

    public function test_convite_inventado_nao_revela_nada(): void
    {
        $this->getJson('/api/convites/token-inventado')
            ->assertStatus(404)
            ->assertJsonPath('situacao', 'invalido')
            ->assertJsonMissingPath('convite');
    }

    /**
     * RN04 — a senha definida no convite observa a mesma política do cadastro.
     */
    public function test_senha_do_convite_obedece_a_politica(): void
    {
        [, $token, $usuario] = $this->conviteDeTutor();

        $this->postJson("/api/convites/{$token}", ['password' => 'curta'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNull($usuario->fresh()->ativado_em);
    }
}
