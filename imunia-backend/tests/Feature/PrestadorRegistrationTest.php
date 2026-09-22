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

    /**
     * O autônomo também se inscreve sob CNPJ: o médico-veterinário não pode ser
     * microempreendedor individual, então não há a figura do prestador pessoa
     * física — o que muda entre os três tipos de RF07a são os rótulos da tela,
     * não o documento pedido.
     */
    public function test_cadastra_profissional_autonomo(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload([
            'tipo' => 'autonomo',
            'nome' => 'Larissa Prado',
            'cnpj' => '26937175000113',
            'responsavel_tecnico_nome' => 'Larissa Prado',
            'responsavel_tecnico_crmv' => '20981',
            'email' => 'larissa@example.com',
        ]));

        $response->assertCreated();
        $response->assertJsonPath('prestador.tipo', 'autonomo');
    }

    /**
     * Um CPF de dígito verificador impecável continua sendo recusado, e é esse
     * o ponto: o cadastro não rejeita o número, rejeita a pessoa física — nem
     * mesmo para o autônomo, que é onde a tentação de informá-lo existe.
     */
    public function test_cpf_e_rejeitado_mesmo_para_o_profissional_autonomo(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload([
            'tipo' => 'autonomo',
            'nome' => 'Larissa Prado',
            'cnpj' => '52998224725',
            'responsavel_tecnico_nome' => 'Larissa Prado',
            'responsavel_tecnico_crmv' => '20981',
            'email' => 'larissa@example.com',
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
