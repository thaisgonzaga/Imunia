<?php

namespace Database\Factories;

use App\Models\Imunobiologico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Imunobiologico>
 */
class ImunobiologicoFactory extends Factory
{
    protected $model = Imunobiologico::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chave' => 'v10-multipla-canina',
            'nome_comercial' => 'V10 múltipla canina',
            'nome_tecnico' => 'Vacina polivalente canina (cinomose, hepatite, parvovirose, leptospirose, coronavirose)',
            'fabricante' => 'Zoetis',
            'agentes_cobertos' => 'cinomose, hepatite, parvovirose, leptospirose, coronavirose',
            'especie_destino' => 'cao',
            'classificacao' => 'essencial',
            'via_administracao_usual' => 'Subcutânea',
            'ativo' => true,
        ];
    }

    public function antirrabica(): static
    {
        return $this->state(fn () => [
            'chave' => 'antirrabica',
            'nome_comercial' => 'antirrábica',
            'nome_tecnico' => 'Vacina antirrábica inativada',
            'fabricante' => 'MSD',
            'agentes_cobertos' => 'raiva',
            'especie_destino' => 'ambas',
            'classificacao' => 'essencial',
            'via_administracao_usual' => 'Subcutânea',
        ]);
    }
}
