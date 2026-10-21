<?php

namespace Tests\Feature;

use App\Models\Prestador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrestadorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tipo' => 'clinica',
            'nome' => 'Clínica Vet Amigo',
            'cnpj' => '11222333000181',
            'telefone' => '(31) 3891-4400',
            'endereco' => 'Rua dos Passos, 120',
            'municipio' => 'Viçosa',
            'uf' => 'MG',
            'responsavel_tecnico_nome' => 'Marcelo Andrade',
            'responsavel_tecnico_crmv' => '12345',
            'responsavel_tecnico_crmv_uf' => 'MG',
            'email' => 'contato@vetamigo.com.br',
            'password' => 'Segredo123',
            'password_confirmation' => 'Segredo123',
        ], $overrides);
    }

    public function test_cadastra_clinica_e_cria_administrador_com_dois_papeis(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('prestador.nome', 'Clínica Vet Amigo');

        $prestador = Prestador::firstWhere('cnpj', '11222333000181');
        $usuario = User::firstWhere('email', 'contato@vetamigo.com.br');

        $this->assertNotNull($prestador);
        $this->assertNotNull($usuario);
        $this->assertTrue($usuario->prestadores()->where('prestadores.id', $prestador->id)->exists());
        $this->assertCount(2, $prestador->usuarios()->get());

        $papeis = $prestador->usuarios()->get()->pluck('pivot.papel')->sort()->values();
        $this->assertEquals(['admin_prestador', 'veterinario'], $papeis->all());
    }

    /** O profissional autônomo se cadastra sem CNPJ: quem o identifica é o CRMV. */
    public function test_cadastra_profissional_autonomo_sem_cnpj(): void
    {
        $payload = $this->payload([
            'tipo' => 'autonomo',
            'nome' => 'Larissa Prado',
            'responsavel_tecnico_nome' => 'Larissa Prado',
            'responsavel_tecnico_crmv' => '20981',
            'email' => 'larissa@example.com',
        ]);
        unset($payload['cnpj']);

        $response = $this->postJson('/api/prestadores', $payload);

        $response->assertCreated();
        $response->assertJsonPath('prestador.tipo', 'autonomo');
        $this->assertDatabaseHas('prestadores', ['nome' => 'Larissa Prado', 'cnpj' => null]);
    }

    /** O índice único do CNPJ admite vários nulos: um autônomo não barra o próximo. */
    public function test_dois_autonomos_sem_cnpj_podem_se_cadastrar(): void
    {
        $this->postJson('/api/prestadores', $this->payload([
            'tipo' => 'autonomo',
            'cnpj' => '',
            'email' => 'larissa@example.com',
        ]))->assertCreated();

        $this->postJson('/api/prestadores', $this->payload([
            'tipo' => 'autonomo',
            'cnpj' => '',
            'email' => 'joao@example.com',
        ]))->assertCreated();

        $this->assertSame(2, Prestador::whereNull('cnpj')->count());
    }

    /** O campo não aparece para o autônomo; se vier mesmo assim, é descartado. */
    public function test_cnpj_enviado_pelo_autonomo_e_descartado(): void
    {
        $this->postJson('/api/prestadores', $this->payload([
            'tipo' => 'autonomo',
            'nome' => 'Larissa Prado',
            'cnpj' => '52998224725',
            'email' => 'larissa@example.com',
        ]))->assertCreated();

        $this->assertDatabaseHas('prestadores', ['nome' => 'Larissa Prado', 'cnpj' => null]);
    }

    public function test_clinica_continua_obrigada_a_informar_cnpj(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload(['cnpj' => '']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cnpj');
    }

    /** Para clínica e hospital, o CPF no lugar do CNPJ continua sendo recusado. */
    public function test_cpf_no_lugar_do_cnpj_e_rejeitado_para_clinica(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload([
            'cnpj' => '52998224725',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cnpj');
    }

    public function test_cnpj_duplicado_e_rejeitado(): void
    {
        $this->postJson('/api/prestadores', $this->payload())->assertCreated();

        $response = $this->postJson('/api/prestadores', $this->payload([
            'email' => 'outro@example.com',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cnpj');
    }

    public function test_campos_obrigatorios_ausentes_retornam_422(): void
    {
        $response = $this->postJson('/api/prestadores', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'tipo', 'nome', 'cnpj', 'telefone', 'endereco',
            'municipio', 'uf', 'responsavel_tecnico_nome',
            'responsavel_tecnico_crmv', 'responsavel_tecnico_crmv_uf',
            'email', 'password',
        ]);
    }

    public function test_cnpj_com_digito_verificador_invalido_e_rejeitado(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload([
            'cnpj' => '11222333000180',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('cnpj');
    }

    public function test_crmv_com_prefixo_ou_letras_e_rejeitado(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload([
            'responsavel_tecnico_crmv' => 'CRMV-MG 12345',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('responsavel_tecnico_crmv');
    }
}
