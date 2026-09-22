<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Autorizacao>
 */
class AutorizacaoFactory extends Factory
{
    protected $model = Autorizacao::class;

    /**
     * Autorização recém-concedida e vigente — o estado em que o prestador
     * efetivamente enxerga o animal (RN48).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'animal_id' => Animal::factory(),
            'prestador_id' => Prestador::factory(),
            'concedida_por_user_id' => User::factory(),
            'concedida_em' => now(),
            'expira_em' => now()->addDays(Autorizacao::PRAZO_DIAS),
            'revogada_em' => null,
        ];
    }

    /**
     * RN39 — o prazo venceu e ninguém renovou. A linha continua; o acesso, não.
     */
    public function expirada(): static
    {
        return $this->state(fn () => [
            'concedida_em' => now()->subDays(Autorizacao::PRAZO_DIAS + 10),
            'expira_em' => now()->subDays(10),
        ]);
    }

    /**
     * Vigente, mas dentro da antecedência em que T12 e V06 pedem renovação
     * (RN39). É o estado âmbar do `AuthorizationCard`.
     */
    public function aExpirar(int $dias = 12): static
    {
        return $this->state(fn () => [
            'concedida_em' => now()->subDays(Autorizacao::PRAZO_DIAS - $dias),
            'expira_em' => now()->addDays($dias),
        ]);
    }

    /**
     * RN40 — o tutor encerrou o acesso antes do prazo.
     */
    public function revogada(): static
    {
        return $this->state(fn () => ['revogada_em' => now()->subDay()]);
    }
}
