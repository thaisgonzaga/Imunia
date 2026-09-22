<?php

namespace Tests\Feature;

use App\Models\Imunobiologico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O comando `imunia:preparar`, que roda a cada início do servidor de produção.
 */
class PreparacaoDeProducaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_semeia_o_catalogo_no_banco_vazio(): void
    {
        $this->artisan('imunia:preparar')->assertSuccessful();

        $this->assertTrue(Imunobiologico::query()->exists());
    }

    public function test_nao_semeia_de_novo_e_preserva_o_que_a_administracao_mudou(): void
    {
        $this->artisan('imunia:preparar')->assertSuccessful();

        $total = Imunobiologico::query()->count();
        $vacina = Imunobiologico::query()->firstOrFail();
        $vacina->update(['fabricante' => 'Alterado em X01']);

        $this->artisan('imunia:preparar')->assertSuccessful();

        $this->assertSame($total, Imunobiologico::query()->count());
        $this->assertSame('Alterado em X01', $vacina->fresh()->fabricante);
    }

    public function test_cria_a_administracao_da_plataforma_com_senha_desconhecida(): void
    {
        config(['app.admin_plataforma_email' => 'thais@example.com']);

        $this->artisan('imunia:preparar')->assertSuccessful();

        $usuario = User::query()->where('email', 'thais@example.com')->firstOrFail();
        $this->assertTrue($usuario->admin_plataforma);
        $this->assertNotNull($usuario->email_verified_at);
        $this->assertContains('admin_plataforma', $usuario->papeis());
    }

    public function test_promove_a_conta_que_ja_existe_sem_trocar_a_senha(): void
    {
        $usuario = User::factory()->create(['email' => 'thais@example.com']);
        $senha = $usuario->password;
        config(['app.admin_plataforma_email' => 'thais@example.com']);

        $this->artisan('imunia:preparar')->assertSuccessful();

        $usuario->refresh();
        $this->assertTrue($usuario->admin_plataforma);
        $this->assertSame($senha, $usuario->password);
    }

    public function test_sem_endereco_configurado_ninguem_vira_administracao(): void
    {
        // Catálogo já presente: fora de produção, a semeadura traria a conta
        // de demonstração, e o que se quer ver aqui é só o comando.
        Imunobiologico::factory()->create();
        config(['app.admin_plataforma_email' => null]);

        $this->artisan('imunia:preparar')->assertSuccessful();

        $this->assertFalse(User::query()->where('admin_plataforma', true)->exists());
    }

    public function test_em_producao_o_catalogo_nao_traz_a_conta_de_senha_publica(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('imunia:preparar')->assertSuccessful();

        $this->assertTrue(Imunobiologico::query()->exists());
        $this->assertFalse(User::query()->where('email', 'admin.plataforma@imunia.app')->exists());
    }
}
