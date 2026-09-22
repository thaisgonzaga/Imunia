<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistroDeAcesso>
 */
class RegistroDeAcessoFactory extends Factory
{
    protected $model = RegistroDeAcesso::class;

    /**
     * O caso central de RF52: uma clínica autorizada leu, no histórico de um
     * animal, registro que outra produziu. É a linha que T14 mostra por inteiro
     * — com prestador, profissional e a autorização sob a qual aconteceu.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'prestador_id' => Prestador::factory(),
            'user_id' => User::factory(),
            'tutor_id' => Tutor::factory(),
            'animal_id' => Animal::factory(),
            'natureza' => RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
            'ocorrido_em' => now(),
        ];
    }

    /**
     * O animal em causa, com o titular que ele tinha na hora — que é como a
     * linha é gravada, e não deduzido depois (ver a migração).
     */
    public function sobre(Animal $animal): static
    {
        return $this->state(fn () => [
            'animal_id' => $animal->id,
            'tutor_id' => $animal->tutor_id,
        ]);
    }

    public function em(string $quando): static
    {
        return $this->state(fn () => ['ocorrido_em' => $quando]);
    }

    public function natureza(string $natureza): static
    {
        return $this->state(fn () => ['natureza' => $natureza]);
    }

    /**
     * RF18b — a busca que revelou apenas a existência do cadastro. Não há
     * animal quando se procurou pelo CPF do tutor: nesse momento o sistema
     * ainda não sabia de animal algum.
     */
    public function buscaPorCpf(Tutor $tutor): static
    {
        return $this->state(fn () => [
            'tutor_id' => $tutor->id,
            'animal_id' => null,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CPF,
        ]);
    }
}
