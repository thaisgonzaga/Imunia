<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Vacinacao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vacinacao>
 */
class VacinacaoFactory extends Factory
{
    protected $model = Vacinacao::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'animal_id' => Animal::factory(),
            'prestador_id' => Prestador::factory(),
            'imunobiologico_id' => Imunobiologico::factory(),
            'protocolo_vacinal_id' => ProtocoloVacinal::factory(),
            'origem' => 'profissional',
            'fabricante' => 'Zoetis',
            'lote' => (string) fake()->unique()->bothify('?##-####'),
            'validade' => now()->addYear()->toDateString(),
            'via_administracao' => 'Subcutânea',
            'sitio_anatomico' => null,
            'local_aplicacao' => null,
            'aplicado_em' => now()->subMonths(2),
            'data_aproximada' => false,
            'ordem_dose' => 1,

            // Sem divergência: o profissional aceitou a ordem que o sistema
            // calculou. O caso interessante — os dois números diferentes — é
            // sempre montado pelo teste que o examina (RF27b).
            'ordem_dose_sugerida' => 1,

            'justificativa_conduta' => null,
            'observacao' => null,
            'aplicador_nome' => 'Dr. Marcelo Andrade',
            'aplicador_crmv' => 'CRMV-MG 12345',

            // Nulo de propósito: a coluna nasceu com V07 e a maior parte dos
            // cenários de teste só precisa do retrato em texto que a carteira
            // exibe. Quem examina autoria (RN27) atribui o usuário de verdade.
            'aplicador_user_id' => null,

            'validade_expirada_confirmada' => false,
            'lancado_por_user_id' => null,
        ];
    }

    /**
     * RF29 — histórico pregresso, com obrigatoriedade reduzida e data
     * imprecisa (RN24, RN25): marcado como não verificado, permanentemente.
     */
    public function pregresso(): static
    {
        return $this->state(fn () => [
            'prestador_id' => null,
            'protocolo_vacinal_id' => null,
            'origem' => 'pregresso',
            'fabricante' => null,
            'lote' => null,
            'validade' => null,
            'via_administracao' => null,
            'data_aproximada' => true,
            'ordem_dose' => null,
            'ordem_dose_sugerida' => null,
            'aplicador_nome' => null,
            'aplicador_crmv' => null,
            'aplicador_user_id' => null,
        ]);
    }

    /**
     * T09 — o tutor lembra que houve uma aplicação, mas não qual vacina foi.
     * Combina-se com `pregresso()`: só o histórico pregresso pode existir sem
     * imunobiológico identificado.
     */
    public function semImunobiologico(): static
    {
        return $this->state(fn () => ['imunobiologico_id' => null]);
    }

    /**
     * T09 — nem a data se sabe. O registro vale como lembrança do que já foi
     * aplicado, e o calendário não calcula nada a partir dele (RF26).
     */
    public function semData(): static
    {
        return $this->state(fn () => ['aplicado_em' => null, 'data_aproximada' => true]);
    }
}
