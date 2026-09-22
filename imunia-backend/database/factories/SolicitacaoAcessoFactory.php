<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\Prestador;
use App\Models\SolicitacaoAcesso;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SolicitacaoAcesso>
 */
class SolicitacaoAcessoFactory extends Factory
{
    protected $model = SolicitacaoAcesso::class;

    /**
     * Pedido recém-feito, à espera de resposta — o estado que T13 cobra do
     * tutor e o único que o contador da moldura conta.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'animal_id' => Animal::factory(),
            'prestador_id' => Prestador::factory(),
            'solicitada_por_user_id' => User::factory(),
            'solicitada_em' => now(),
            'expira_em' => now()->addDays(SolicitacaoAcesso::PRAZO_DIAS),
            'recusada_em' => null,
            'atendida_em' => null,
        ];
    }

    /**
     * RF38b — ninguém respondeu no prazo. A linha continua; a pergunta, não.
     */
    public function expirada(): static
    {
        return $this->state(fn () => [
            'solicitada_em' => now()->subDays(SolicitacaoAcesso::PRAZO_DIAS + 5),
            'expira_em' => now()->subDays(5),
        ]);
    }

    /**
     * O tutor disse não. Sem motivo, porque não há campo para ele.
     */
    public function recusada(): static
    {
        return $this->state(fn () => ['recusada_em' => now()->subDay()]);
    }

    /**
     * O tutor autorizou, e a concessão de T11 deu baixa no pedido.
     */
    public function atendida(): static
    {
        return $this->state(fn () => ['atendida_em' => now()->subDay()]);
    }
}
