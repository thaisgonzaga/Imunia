<?php

namespace Database\Factories;

use App\Models\Tutor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tutor>
 */
class TutorFactory extends Factory
{
    protected $model = Tutor::class;

    /**
     * O CPF sai sem dígito verificador válido de propósito: a conferência mora
     * em `App\Rules\CpfValido`, aplicada no autocadastro (RF12),
     * e repeti-la aqui daria a falsa impressão de que o modelo a exerce.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'nome' => 'Helena Ramos',
            'cpf' => (string) fake()->unique()->numerify('###########'),
            'termos_aceitos_em' => now(),
        ];
    }
}
