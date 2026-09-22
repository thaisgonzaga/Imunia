<?php

namespace Database\Factories;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Atendimento>
 */
class AtendimentoFactory extends Factory
{
    protected $model = Atendimento::class;

    /**
     * O atendimento do cenário canônico (§8.2 do briefing): a dermatite do Théo
     * em 12/11/2025, na Clínica Vet Amigo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'animal_id' => Animal::factory(),
            'prestador_id' => Prestador::factory(),
            'retifica_atendimento_id' => null,
            'motivo_retificacao' => null,
            'atendido_em' => '2025-11-12 16:20:00',
            'titulo' => 'Dermatite',
            'motivo' => 'Coceira intensa e falhas no pelo na região das costas, há cerca de duas semanas.',
            'anamnese' => 'Tutora relata início após passeios em área com mato alto. Sem mudança de alimentação. Nenhum outro animal na casa.',
            'exame_fisico' => 'Peso 12,4 kg. Temperatura 38,6 °C. Lesões avermelhadas com descamação na região dorsal, sem odor. Linfonodos normais.',
            'hipoteses_diagnosticas' => 'Dermatite alérgica por picada de ectoparasita; dermatite por ácaros.',
            'diagnostico' => 'Aguardando resultado do raspado cutâneo coletado nesta consulta.',
            'conduta' => 'Banho com xampu específico duas vezes por semana. Evitar a área de mato até o retorno.',
            'profissional_user_id' => User::factory(),
            'profissional_nome' => 'Dr. Marcelo Andrade',
            'profissional_crmv' => 'CRMV-MG 12345',
            'retorno_em' => null,
            'retorno_finalidade' => null,
        ];
    }

    /**
     * RF34 — retorno programado, com data prevista e finalidade descrita.
     */
    public function comRetorno(string $em = '2025-11-15', string $finalidade = 'Reavaliação com o resultado do raspado.'): static
    {
        return $this->state(fn () => [
            'retorno_em' => $em,
            'retorno_finalidade' => $finalidade,
        ]);
    }

    /**
     * RF33 — a correção de um registro é outro registro, vinculado ao primeiro,
     * com o conteúdo corrigido, o motivo, a autoria e a data. O original
     * permanece íntegro: nada aqui o altera.
     */
    public function retificando(Atendimento $original, string $motivo = 'Resultado do exame recebido três dias depois da consulta.'): static
    {
        return $this->state(fn () => [
            'animal_id' => $original->animal_id,
            'prestador_id' => $original->prestador_id,
            'profissional_user_id' => $original->profissional_user_id,
            'profissional_nome' => $original->profissional_nome,
            'profissional_crmv' => $original->profissional_crmv,
            'retifica_atendimento_id' => $original->id,
            'motivo_retificacao' => $motivo,

            // A consulta aconteceu quando aconteceu (V09): a retificação
            // conserva a data do original, e a data da correção é o
            // `created_at`. Uma retificação com data própria inventaria um
            // atendimento que ninguém prestou.
            'atendido_em' => $original->atendido_em,
        ]);
    }
}
