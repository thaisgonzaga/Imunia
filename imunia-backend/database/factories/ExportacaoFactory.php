<?php

namespace Database\Factories;

use App\Models\Exportacao;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Exportacao>
 */
class ExportacaoFactory extends Factory
{
    protected $model = Exportacao::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'codigo' => Str::upper(bin2hex(random_bytes(Exportacao::COMPRIMENTO_CODIGO / 2))),
            'resumo' => hash('sha256', Str::random(64)),
            'animal_nome' => 'Théo',
            'animal_especie' => 'cao',
            'emitido_por' => null,
            'emitido_em' => Carbon::parse('2026-01-12 09:30:00'),
        ];
    }
}
