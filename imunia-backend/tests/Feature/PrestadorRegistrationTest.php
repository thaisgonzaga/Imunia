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
            'documento' => '11222333000181',
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

        $prestador = Prestador::firstWhere('documento', '11222333000181');
        $usuario = User::firstWhere('email', 'contato@vetamigo.com.br');

        $this->assertNotNull($prestador);
        $this->assertNotNull($usuario);
        $this->assertTrue($usuario->prestadores()->where('prestadores.id', $prestador->id)->exists());
        $this->assertCount(2, $prestador->usuarios()->get());

        $papeis = $prestador->usuarios()->get()->pluck('pivot.papel')->sort()->values();
        $this->assertEquals(['admin_prestador', 'veterinario'], $papeis->all());
    }

    public function test_cadastra_profissional_autonomo(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload([
            'tipo' => 'autonomo',
            'nome' => 'Larissa Prado',
            'documento' => '52998224725',
            'responsavel_tecnico_nome' => 'Larissa Prado',
            'responsavel_tecnico_crmv' => '20981',
            'email' => 'larissa@example.com',
        ]));

        $response->assertCreated();
        $response->assertJsonPath('prestador.tipo', 'autonomo');
    }

    public function test_documento_duplicado_e_rejeitado(): void
    {
        $this->postJson('/api/prestadores', $this->payload())->assertCreated();

        $response = $this->postJson('/api/prestadores', $this->payload([
            'email' => 'outro@example.com',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('documento');
    }

    public function test_campos_obrigatorios_ausentes_retornam_422(): void
    {
        $response = $this->postJson('/api/prestadores', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'tipo', 'nome', 'documento', 'telefone', 'endereco',
            'municipio', 'uf', 'responsavel_tecnico_nome',
            'responsavel_tecnico_crmv', 'responsavel_tecnico_crmv_uf',
            'email', 'password',
        ]);
    }

    public function test_documento_com_digito_verificador_invalido_e_rejeitado(): void
    {
        $response = $this->postJson('/api/prestadores', $this->payload([
            'documento' => '11222333000180',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('documento');
    }
}
