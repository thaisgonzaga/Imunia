<?php

namespace Tests\Feature;

use App\Models\Animal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnimalTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_cadastro_gera_o_codigo_unico_do_animal(): void
    {
        // RF17c — seja qual for a origem do cadastro.
        $animal = Animal::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^IM-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}$/',
            $animal->codigo,
        );
    }

    public function test_codigos_de_animais_distintos_nao_se_repetem_nem_se_seguem(): void
    {
        $codigos = Animal::factory()->count(20)->create()
            ->pluck('codigo');

        // RN15 — único e não sequencial: 20 sorteios não podem colidir nem
        // formar série que permita adivinhar o próximo.
        $this->assertCount(20, $codigos->unique());
    }

    public function test_o_codigo_nao_muda_quando_o_cadastro_e_atualizado(): void
    {
        // RF17b — o código acompanha o animal por toda a vida, inclusive quando
        // o veterinário completa a caracterização ou o tutor troca o nome.
        $animal = Animal::factory()->create(['nome' => 'Théo']);
        $codigo = $animal->codigo;

        $animal->update(['nome' => 'Théo Ramos']);
        $animal->forceFill(['caracterizado_em' => now()])->save();

        $this->assertSame($codigo, $animal->fresh()->codigo);
    }

    public function test_o_codigo_nao_e_atribuivel_em_massa(): void
    {
        // Nem o tutor nem o prestador escolhem o código: ele é do sistema.
        $animal = Animal::factory()->create();
        $animal->fill(['codigo' => 'IM-AAAA-AAAA']);

        $this->assertNotSame('IM-AAAA-AAAA', $animal->codigo);
    }
}
