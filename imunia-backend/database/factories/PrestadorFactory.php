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
            'cnpj' => $this->cnpj(),
            'telefone' => '(31) 3899-1000',
            'endereco' => 'Rua dos Estudantes, 120',
            'municipio' => 'Viçosa',
            'uf' => 'MG',
            'responsavel_tecnico_nome' => 'Marcelo Andrade',
            'responsavel_tecnico_crmv' => '12345',
            'responsavel_tecnico_crmv_uf' => 'MG',
        ];
    }

    /**
     * Um CNPJ que passa por `App\Rules\CnpjValido`, e não catorze dígitos
     * quaisquer: o cenário do teste que edita o cadastro em A02 reenvia o
     * documento inteiro a cada salvamento, e a regra o recusaria. Nascer válido
     * poupa cada teste de sobrescrever o campo só para chegar ao que quer
     * exercitar.
     *
     * A raiz é única, e os dígitos verificadores derivam dela — logo o CNPJ
     * inteiro também é, e a coluna `unique` não colide entre dois prestadores.
     */
    private function cnpj(): string
    {
        $cnpj = (string) fake()->unique()->numerify('############');

        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }
            $resto = $soma % 11;
            $cnpj .= $resto < 2 ? 0 : 11 - $resto;
        }

        return $cnpj;
    }
}
