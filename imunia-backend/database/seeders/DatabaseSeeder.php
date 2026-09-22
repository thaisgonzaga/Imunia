<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * O `WithoutModelEvents` que vinha do esqueleto do Laravel saiu daqui: o
     * código único do animal (RF17c) é gerado no evento `creating` do modelo, e
     * com os eventos desligados `db:seed` morria em toda criação de animal. A
     * geração fica no modelo de propósito — o código nasce com o cadastro, seja
     * qual for a origem dele —, e é o seeder que precisa respeitar isso.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(CenarioDemonstracaoSeeder::class);

        // O plantel da clínica depende do cenário acima: é lá que nascem a
        // Clínica Vet Amigo, o Dr. Marcelo e o Théo.
        $this->call(CenarioClinicaSeeder::class);

        // A conta vista de dentro (A01 a A03). Vem por último porque o convite
        // de equipe reaproveita a Dra. Beatriz, que nasce no plantel acima — é
        // ela que demonstra o vínculo com dois prestadores de RF09b.
        $this->call(CenarioEquipeSeeder::class);
    }
}
