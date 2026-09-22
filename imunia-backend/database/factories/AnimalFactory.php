<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\Tutor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Animal>
 */
class AnimalFactory extends Factory
{
    protected $model = Animal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tutor_id' => Tutor::factory(),
            'nome' => 'Théo',
            'especie' => 'cao',
            'sexo' => 'macho',
            'nascimento_em' => now()->subMonths(18)->toDateString(),
            'nascimento_exato' => false,
        ];
    }

    /**
     * Cadastro já completado por veterinário (RF19), que encerra a condição
     * preliminar de RN17.
     */
    public function caracterizado(): static
    {
        return $this->afterCreating(function (Animal $animal): void {
            $animal->forceFill(['caracterizado_em' => now()])->save();
        });
    }

    /**
     * Animal chipado. O número é da caracterização (RF19) e não é atribuível em
     * massa, como `caracterizado_em` — daí o mesmo caminho de escrita forçada.
     */
    public function comMicrochip(string $numero): static
    {
        return $this->afterCreating(function (Animal $animal) use ($numero): void {
            $animal->forceFill(['microchip' => $numero])->save();
        });
    }

    public function gato(): static
    {
        return $this->state(fn () => [
            'nome' => 'Nina',
            'especie' => 'gato',
            'sexo' => 'femea',
            'nascimento_em' => null,
        ]);
    }
}
