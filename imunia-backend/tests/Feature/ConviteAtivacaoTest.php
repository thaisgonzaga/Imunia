<?php

namespace Tests\Feature;

use App\Models\Convite;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\ConviteDeAtivacao;
use App\Support\DocumentosLegais;
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
            'aceite_termos' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('usuario.rota_inicial', '/inicio');

        $usuario->refresh();
        $this->assertTrue(Hash::check('Segredo123', $usuario->password));
        $this->assertNotNull($usuario->ativado_em);
        $this->assertNotNull($convite->fresh()->aceito_em);
    }

    /**
     * O cadastro em RF12 é feito pela clínica, e ninguém aceita termos em nome
     * de outro: a ativação é a primeira vez que o titular está diante do
     * documento, e é aqui que o aceite passa a existir — com a versão.
     */
    public function test_ativacao_de_tutor_registra_o_aceite_com_a_versao(): void
    {
        [, $token, $usuario] = $this->conviteDeTutor();

        $tutor = $usuario->tutor;
        $this->assertNull($tutor->termos_aceitos_em);

        $this->postJson("/api/convites/{$token}", [
            'password' => 'Segredo123',
            'aceite_termos' => true,
        ])->assertOk();

        $tutor->refresh();
        $this->assertNotNull($tutor->termos_aceitos_em);
        $this->assertSame(DocumentosLegais::VERSAO, $tutor->termos_versao);
    }

    public function test_tutor_nao_ativa_a_conta_sem_aceitar_os_termos(): void
    {
        [, $token, $usuario] = $this->conviteDeTutor();

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('aceite_termos');

        $usuario->refresh();
        $this->assertNull($usuario->ativado_em);
        $this->assertNull($usuario->tutor->termos_aceitos_em);
    }

    /**
     * Quem respondeu pelos termos do estabelecimento foi quem o cadastrou, em
     * P03. Pedir de novo ao profissional convidado seria pedir aceite de um
     * contrato que não é o dele.
     */
    public function test_convite_de_veterinario_nao_pede_aceite(): void
    {
        [, $token] = $this->conviteDeVeterinario();

        $this->getJson("/api/convites/{$token}")
            ->assertOk()
            ->assertJsonPath('convite.aceita_termos', false);

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])->assertOk();
    }

    /**
     * A tela precisa saber se desenha a caixa: sem isso, ou ela some para quem
     * deve aceitar, ou aparece para quem já aceitou.
     */
    public function test_convite_de_tutor_anuncia_que_pede_aceite(): void
    {
        [, $token] = $this->conviteDeTutor();

        $this->getJson("/api/convites/{$token}")
            ->assertOk()
            ->assertJsonPath('convite.aceita_termos', true);
    }

    /**
     * RF14c — a ativação verifica o endereço e dispensa RF05.
     */
    public function test_aceite_confirma_o_endereco_automaticamente(): void
    {
        [, $token, $usuario] = $this->conviteDeTutor();

        $this->postJson("/api/convites/{$token}", [
            'password' => 'Segredo123',
            'aceite_termos' => true,
        ])->assertOk();

        $this->assertNotNull($usuario->fresh()->email_verified_at);
    }

    public function test_aceite_ja_deixa_a_sessao_estabelecida(): void
    {
        [, $token, $usuario] = $this->conviteDeTutor();

        $this->postJson("/api/convites/{$token}", [
            'password' => 'Segredo123',
            'aceite_termos' => true,
        ])->assertOk();

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

        $this->postJson("/api/convites/{$token}", [
            'password' => 'Segredo123',
            'aceite_termos' => true,
        ])->assertOk();

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

        $this->postJson("/api/convites/{$token}", [
            'password' => 'Segredo123',
            'aceite_termos' => true,
        ])->assertOk();

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

        $this->postJson("/api/convites/{$token}", [
            'password' => 'curta',
            'aceite_termos' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNull($usuario->fresh()->ativado_em);
    }

    /**
     * O convite emitido por A03 para uma conta que ainda não existe: sem nome e
     * sem senha, os dois definidos aqui pelo titular (RF09).
     *
     * @return array{0: string, 1: User}
     */
    private function conviteParaContaNova(): array
    {
        $prestador = Prestador::factory()->create();

        $convidado = User::factory()->unverified()->create([
            'name' => '',
            'email' => 'beatriz@example.com',
            'ativado_em' => null,
        ]);
        $prestador->usuarios()->attach($convidado->id, [
            'papel' => 'veterinario',
            'crmv' => '18220',
            'crmv_uf' => 'MG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [, $token] = Convite::emitir($convidado, $prestador, 'veterinario');

        return [$token, $convidado];
    }

    /**
     * RF09 — o mesmo profissional mantém vínculo com mais de um prestador. O
     * segundo convite não é um cadastro: é uma confirmação de vínculo, e não
     * pode redefinir a senha com que ele entra no primeiro.
     *
     * @return array{0: string, 1: User}
     */
    private function conviteParaQuemJaTemConta(): array
    {
        $petCenter = Prestador::factory()->create(['nome' => 'Pet Center', 'cnpj' => '11444777000161']);
        $vetAmigo = Prestador::factory()->create();

        $beatriz = User::factory()->create([
            'name' => 'Beatriz Salles',
            'email' => 'beatriz@petcenter.example.com',
            'password' => Hash::make('SenhaAntiga123'),
            'ativado_em' => Carbon::parse('2026-01-10'),
        ]);
        $petCenter->usuarios()->attach($beatriz->id, [
            'papel' => 'veterinario', 'crmv' => '18220', 'crmv_uf' => 'MG',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $vetAmigo->usuarios()->attach($beatriz->id, [
            'papel' => 'veterinario', 'crmv' => '18220', 'crmv_uf' => 'MG',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        [, $token] = Convite::emitir($beatriz, $vetAmigo, 'veterinario');

        return [$token, $beatriz];
    }

    public function test_convite_para_conta_nova_pede_nome_e_senha(): void
    {
        [$token] = $this->conviteParaContaNova();

        $this->getJson("/api/convites/{$token}")
            ->assertOk()
            ->assertJsonPath('convite.define_senha', true)
            ->assertJsonPath('convite.define_nome', true)
            ->assertJsonPath('convite.nome', null)
            ->assertJsonPath('convite.crmv', '18220');

        $this->postJson("/api/convites/{$token}", ['password' => 'Segredo123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nome');
    }

    public function test_conta_nova_define_o_proprio_nome_ao_aceitar(): void
    {
        [$token, $convidado] = $this->conviteParaContaNova();

        $this->postJson("/api/convites/{$token}", [
            'nome' => 'Beatriz Salles',
            'password' => 'Segredo123',
        ])->assertOk();

        $this->assertSame('Beatriz Salles', $convidado->fresh()->name);
        $this->assertNotNull($convidado->fresh()->ativado_em);
    }

    /** RF09 — quem já tem conta não redefine coisa alguma; só confirma o vínculo. */
    public function test_convite_para_quem_ja_tem_conta_nao_pede_senha(): void
    {
        [$token] = $this->conviteParaQuemJaTemConta();

        $this->getJson("/api/convites/{$token}")
            ->assertOk()
            ->assertJsonPath('convite.define_senha', false)
            ->assertJsonPath('convite.define_nome', false)
            ->assertJsonPath('convite.nome', 'Beatriz Salles');

        $this->postJson("/api/convites/{$token}", [])->assertOk();
    }

    public function test_aceite_de_quem_ja_tem_conta_preserva_a_senha_e_a_data_de_ativacao(): void
    {
        [$token, $beatriz] = $this->conviteParaQuemJaTemConta();
        $senhaAntiga = $beatriz->password;

        $this->postJson("/api/convites/{$token}", [])->assertOk();

        $atualizada = $beatriz->fresh();

        $this->assertSame($senhaAntiga, $atualizada->password);
        $this->assertTrue(Hash::check('SenhaAntiga123', $atualizada->password));
        // RF14b — a data de ativação marca a primeira vez, e só ela.
        $this->assertStringStartsWith('2026-01-10', (string) $atualizada->ativado_em);
    }

    /**
     * O CRMV exibido em P07 é o do vínculo de veterinário. Quem acumula os dois
     * papéis no mesmo prestador tem duas linhas no pivô, e a de administrador
     * não carrega inscrição alguma.
     */
    public function test_o_crmv_exibido_vem_do_vinculo_de_veterinario(): void
    {
        $prestador = Prestador::factory()->create();

        $convidado = User::factory()->unverified()->create(['name' => '', 'ativado_em' => null]);
        $prestador->usuarios()->attach($convidado->id, [
            'papel' => 'admin_prestador', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $prestador->usuarios()->attach($convidado->id, [
            'papel' => 'veterinario', 'crmv' => '31447', 'crmv_uf' => 'MG',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        [, $token] = Convite::emitir($convidado, $prestador, 'veterinario');

        $this->getJson("/api/convites/{$token}")
            ->assertOk()
            ->assertJsonPath('convite.crmv', '31447')
            ->assertJsonPath('convite.crmv_uf', 'MG');
    }
}
