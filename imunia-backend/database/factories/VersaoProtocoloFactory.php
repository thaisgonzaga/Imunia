<?php

namespace Database\Factories;

use App\Models\VersaoProtocolo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VersaoProtocolo>
 */
class VersaoProtocoloFactory extends Factory
{
    protected $model = VersaoProtocolo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // O rótulo é único no banco, e um teste que crie duas versões sem
            // se importar com o nome delas não deveria falhar por isso.
            'rotulo' => fake()->unique()->numberBetween(2000, 2999).'.1',
            'situacao' => VersaoProtocolo::VIGENTE,
            'base' => 'WSAVA 2024',
            'publicado_em' => now()->subYear(),
            'encerrado_em' => null,
        ];
    }

    public function rascunho(): static
    {
        return $this->state(fn () => [
            'situacao' => VersaoProtocolo::RASCUNHO,
            'publicado_em' => null,
            'encerrado_em' => null,
        ]);
    }

    public function encerrada(): static
    {
        return $this->state(fn () => [
            'situacao' => VersaoProtocolo::ENCERRADA,
            'publicado_em' => now()->subYears(5),
            'encerrado_em' => now()->subYear(),
        ]);
    }
}
