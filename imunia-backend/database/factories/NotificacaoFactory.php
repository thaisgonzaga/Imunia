<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Notificacao;
use App\Models\Tutor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notificacao>
 */
class NotificacaoFactory extends Factory
{
    protected $model = Notificacao::class;

    /**
     * O caso comum: o aviso saiu na data prevista da dose e o provedor
     * confirmou a entrega. É o estado em que a coluna de V02 diz ao profissional
     * que o telefonema é redundante.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'animal_id' => Animal::factory(),
            'tutor_id' => Tutor::factory(),
            // O endereço do tutor no momento do envio, como o motor o gravaria.
            'destinatario' => fn (array $atributos) => Tutor::find($atributos['tutor_id'])?->user?->email
                ?? fake()->safeEmail(),
            'imunobiologico_id' => Imunobiologico::factory(),
            'tipo' => 'aviso_na_data',
            'referente_a' => now()->toDateString(),
            'enviada_em' => now(),
            'situacao' => 'entregue',
        ];
    }

    /**
     * A mensagem saiu e ninguém confirmou o recebimento. Não é falha — é o
     * estado em que a rechamada por telefone continua valendo a pena.
     */
    public function semConfirmacao(): static
    {
        return $this->state(fn () => ['situacao' => 'sem_confirmacao']);
    }

    /** O provedor recusou a entrega. Aqui o telefonema é a única via restante. */
    public function falhou(): static
    {
        return $this->state(fn () => ['situacao' => 'falhou']);
    }

    /** RN44 — o terceiro e último aviso de uma dose prevista. */
    public function alertaDeAtraso(): static
    {
        return $this->state(fn () => ['tipo' => 'alerta_atraso']);
    }
}
