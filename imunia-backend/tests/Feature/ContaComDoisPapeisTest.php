<?php

namespace Tests\Feature;

use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Um endereço de correio, uma conta, os papéis que a pessoa tiver (RN05).
 *
 * O veterinário que também é tutor de um animal é a Larissa das personas, e era
 * exatamente quem o sistema não sabia cadastrar: o autocadastro criava conta
 * nova e recusava o endereço repetido, de modo que ser as duas coisas exigia
 * dois endereços — e dois endereços são duas pessoas para o resto do sistema,
 * do livro de acessos à autoria dos registros.
 *
 * O acréscimo acontece pelo cadastro de tutor feito pela clínica (V04), que
 * anexa o papel à conta existente do endereço, e pelo convite de equipe de A03. O cadastro público de estabelecimento (P04) não participa: ele
 * é tela de porta, aberta a quem não tem conta, e não lê a sessão de quem a
 * preenche.
 */
class ContaComDoisPapeisTest extends TestCase
{
    use RefreshDatabase;

    private function dadosDoPrestador(array $overrides = []): array
    {
        return array_merge([
            'tipo' => 'clinica',
            'nome' => 'Clínica Bons Amigos',
            'cnpj' => '11222333000181',
            'telefone' => '31999990000',
            'endereco' => 'Rua das Acácias, 120',
            'municipio' => 'Belo Horizonte',
            'uf' => 'MG',
            'responsavel_tecnico_nome' => 'Marcelo Andrade',
            'responsavel_tecnico_crmv' => '12345',
            'responsavel_tecnico_crmv_uf' => 'MG',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // O cadastro público de estabelecimento não olha para a sessão
    // ------------------------------------------------------------------

    /**
     * Quem preenche P04 nem sempre é quem vai administrar o estabelecimento —
     * pode ser o colega que abre o consultório ao lado, no navegador de quem já
     * está no Imunia. A conta administradora é a que o formulário informa, e o
     * CRMV do responsável técnico não vira papel de quem apenas digitou.
     */
    public function test_cadastro_publico_nao_vincula_o_estabelecimento_a_sessao_aberta(): void
    {
        $usuario = User::factory()->create(['name' => 'Larissa Prado']);
        Tutor::factory()->for($usuario)->create(['nome' => 'Larissa Prado']);

        $resposta = $this->actingAs($usuario)->postJson('/api/prestadores', $this->dadosDoPrestador([
            'tipo' => 'autonomo',
            'nome' => 'Marcelo Andrade — atendimento domiciliar',
            'email' => 'marcelo@example.com',
            'password' => 'Segredo123',
            'password_confirmation' => 'Segredo123',
        ]));

        $resposta->assertCreated();

        $administrador = User::firstWhere('email', 'marcelo@example.com');
        $this->assertNotNull($administrador);

        // A conta de quem estava no navegador segue com os papéis que tinha.
        $this->assertSame(['tutor'], $usuario->refresh()->papeis());
        $this->assertEqualsCanonicalizing(
            ['admin_prestador', 'veterinario'],
            $administrador->papeis(),
        );
    }

    /**
     * E o endereço continua exigido de quem quer que envie o formulário: sem
     * ele não haveria a quem entregar a administração do que se cadastrou.
     */
    public function test_endereco_e_senha_sao_exigidos_mesmo_de_quem_ja_esta_em_uma_conta(): void
    {
        $usuario = User::factory()->create();

        $resposta = $this->actingAs($usuario)->postJson('/api/prestadores', $this->dadosDoPrestador());

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * O endereço que já tem conta é recusado como sempre foi — o acréscimo de
     * vínculo a uma conta existente é do convite de A03, onde alguém de dentro
     * do estabelecimento atesta quem entra.
     */
    public function test_endereco_de_conta_existente_nao_cadastra_estabelecimento(): void
    {
        $usuario = User::factory()->create(['email' => 'larissa@example.com']);

        $resposta = $this->actingAs($usuario)->postJson('/api/prestadores', $this->dadosDoPrestador([
            'email' => 'larissa@example.com',
            'password' => 'Segredo123',
            'password_confirmation' => 'Segredo123',
        ]));

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors(['email']);
        $this->assertSame(0, Prestador::query()->count());
    }

    public function test_visitante_anonimo_cria_conta_e_estabelecimento_juntos(): void
    {
        $resposta = $this->postJson('/api/prestadores', $this->dadosDoPrestador([
            'email' => 'marcelo@example.com',
            'password' => 'Segredo123',
            'password_confirmation' => 'Segredo123',
        ]));

        $resposta->assertCreated();

        $this->assertNotNull(User::firstWhere('email', 'marcelo@example.com'));
    }

    // ------------------------------------------------------------------
    // O veterinário que também tem um animal
    // ------------------------------------------------------------------

    public function test_entrada_pelo_papel_de_tutor_leva_ao_painel_do_tutor(): void
    {
        $usuario = $this->contaComOsDoisPapeis();

        $resposta = $this->postJson('/api/sessao', [
            'email' => $usuario->email,
            'password' => 'Segredo123',
            'papel' => 'tutor',
        ]);

        $resposta->assertOk();
        $resposta->assertJsonPath('usuario.rota_inicial', '/inicio');
        $resposta->assertJsonPath('usuario.papel_ausente', null);
    }

    public function test_entrada_pelo_papel_de_veterinario_leva_ao_ambiente_clinico(): void
    {
        $usuario = $this->contaComOsDoisPapeis();

        $resposta = $this->postJson('/api/sessao', [
            'email' => $usuario->email,
            'password' => 'Segredo123',
            'papel' => 'veterinario',
        ]);

        $resposta->assertOk();
        $resposta->assertJsonPath('usuario.rota_inicial', '/clinica/painel');
    }

    /**
     * Sem escolha de papel, a precedência de sempre (RF01a): quem acumula cai
     * no ambiente de registro.
     */
    public function test_entrada_sem_papel_mantem_a_precedencia_de_sempre(): void
    {
        $usuario = $this->contaComOsDoisPapeis();

        $this->postJson('/api/sessao', ['email' => $usuario->email, 'password' => 'Segredo123'])
            ->assertOk()
            ->assertJsonPath('usuario.rota_inicial', '/clinica/painel');
    }

    /**
     * A credencial certa na porta errada não é erro de credencial: a sessão
     * abre, e a tela é que oferece criar o cadastro que falta.
     */
    public function test_papel_que_a_conta_nao_tem_abre_a_sessao_e_se_anuncia(): void
    {
        $usuario = User::factory()->create(['password' => Hash::make('Segredo123')]);
        Tutor::factory()->for($usuario)->create();

        $resposta = $this->postJson('/api/sessao', [
            'email' => $usuario->email,
            'password' => 'Segredo123',
            'papel' => 'veterinario',
        ]);

        $resposta->assertOk();
        $resposta->assertJsonPath('usuario.papel_ausente', 'veterinario');
        $this->assertAuthenticatedAs($usuario);
    }

    /**
     * Quem administra a conta sem atender (RN08) entra pela mesma porta do
     * profissional e cai na administração — mandá-lo a V01 seria recusá-lo na
     * guarda no instante seguinte.
     */
    public function test_administrador_sem_vinculo_clinico_entra_pela_porta_do_profissional(): void
    {
        $usuario = User::factory()->create(['password' => Hash::make('Segredo123')]);
        $prestador = Prestador::factory()->create();
        $prestador->usuarios()->attach($usuario->id, [
            'papel' => 'admin_prestador',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/sessao', [
            'email' => $usuario->email,
            'password' => 'Segredo123',
            'papel' => 'veterinario',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.rota_inicial', '/prestador')
            ->assertJsonPath('usuario.papel_ausente', null);
    }

    private function contaComOsDoisPapeis(): User
    {
        $usuario = User::factory()->create(['password' => Hash::make('Segredo123')]);

        Tutor::factory()->for($usuario)->create();

        $prestador = Prestador::factory()->create();
        $prestador->usuarios()->attach($usuario->id, [
            'papel' => 'veterinario',
            'crmv' => '12345',
            'crmv_uf' => 'MG',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $usuario;
    }
}
