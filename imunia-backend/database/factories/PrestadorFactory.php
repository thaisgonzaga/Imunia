<?php

namespace Database\Factories;

use App\Models\Prestador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prestador>
 */
class PrestadorFactory extends Factory
{
    protected $model = Prestador::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo' => 'clinica',
            'nome' => 'Clínica Vet Amigo',
            'documento' => (string) fake()->unique()->numerify('##############'),
            'telefone' => '(31) 3899-1000',
            'endereco' => 'Rua dos Estudantes, 120',
            'municipio' => 'Viçosa',
            'uf' => 'MG',
            'responsavel_tecnico_nome' => 'Marcelo Andrade',
            'responsavel_tecnico_crmv' => '12345',
            'responsavel_tecnico_crmv_uf' => 'MG',
        ];
    }
}
