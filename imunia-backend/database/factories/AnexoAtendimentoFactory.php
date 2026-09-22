<?php

namespace Database\Factories;

use App\Models\AnexoAtendimento;
use App\Models\Atendimento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnexoAtendimento>
 */
class AnexoAtendimentoFactory extends Factory
{
    protected $model = AnexoAtendimento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'atendimento_id' => Atendimento::factory(),
            'descricao' => 'Raspado cutâneo',
            'exame_em' => '2025-11-12',
            'tipo' => 'imagem',
            'mime' => 'image/png',
            'tamanho_bytes' => 48_320,
            'caminho' => 'anexos/'.fake()->unique()->uuid().'.png',
        ];
    }

    public function documento(): static
    {
        return $this->state(fn () => [
            'descricao' => 'Laudo do raspado',
            'exame_em' => '2025-11-15',
            'tipo' => 'documento',
            'mime' => 'application/pdf',
            'tamanho_bytes' => 128_450,
            'caminho' => 'anexos/'.fake()->unique()->uuid().'.pdf',
        ]);
    }
}
