<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use App\Models\VersaoProtocolo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VacinacaoDetalheControllerTest extends TestCase
{
    use RefreshDatabase;

    private function helena(): Tutor
    {
        $usuario = User::factory()->create([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
        ]);

        return Tutor::factory()->create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
        ]);
    }

    public function test_o_detalhe_exige_sessao(): void
    {
        $this->getJson('/api/animais/IM-7F3K-92QD/vacinas/1')->assertUnauthorized();
    }

    public function test_vacinacao_inexistente_responde_404(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$animal->codigo}/vacinas/999")
            ->assertNotFound();
    }

    // RN12 — vacinação de outro animal (mesmo que exista) responde 404, igual
    // a um id inexistente.
    public function test_vacinacao_de_outro_animal_responde_404(): void
    {
        $helena = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);

        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animalDeOutrem = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $vacinacao = Vacinacao::factory()->create([
            'animal_id' => $animalDeOutrem->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => ProtocoloVacinal::factory()->antirrabica()->create([
                'imunobiologico_id' => $imunobiologico->id,
            ])->id,
        ]);

        $this->actingAs($helena->user)
            ->getJson("/api/animais/{$animal->codigo}/vacinas/{$vacinacao->id}")
            ->assertNotFound();
    }

    public function test_detalhe_de_aplicacao_profissional(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $prestador = Prestador::factory()->create(['nome' => 'Clínica Vida Animal']);

        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $protocolo = ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
            'versao_protocolo_id' => VersaoProtocolo::factory()->create(['rotulo' => 'WSAVA 2024.1'])->id,
        ]);

        $vacinacao = Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonths(2)->setTime(14, 30),
            'ordem_dose' => 1,
        ]);

        $resposta = $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$animal->codigo}/vacinas/{$vacinacao->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('aplicacao.id', $vacinacao->id);
        $resposta->assertJsonPath('aplicacao.origem', 'profissional');
        $resposta->assertJsonPath('aplicacao.fabricante', $vacinacao->fabricante);
        $resposta->assertJsonPath('aplicacao.hora', '14:30');
        $resposta->assertJsonPath('aplicacao.protocolo_versao', 'WSAVA 2024.1');
        $resposta->assertJsonPath('aplicacao.aplicador.prestador', 'Clínica Vida Animal');
        $resposta->assertJsonPath('imunobiologico.nome', $imunobiologico->nome_comercial);
        $resposta->assertJsonPath('proxima_dose.regra_texto', 'Reforço anual, contado a partir da última aplicação ('.$vacinacao->aplicado_em->format('d/m/Y').').');
    }

    // RN24, RN25 — o pregresso não tem hora nem cálculo de próxima dose.
    public function test_detalhe_de_aplicacao_pregressa_nao_tem_hora_nem_proxima_dose(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();

        $vacinacao = Vacinacao::factory()->pregresso()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $imunobiologico->id,
            'aplicado_em' => '2024-01-01',
        ]);

        $resposta = $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$animal->codigo}/vacinas/{$vacinacao->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('aplicacao.origem', 'pregresso');
        $resposta->assertJsonPath('aplicacao.hora', null);
        $resposta->assertJsonPath('aplicacao.rotulo', 'Aplicação anterior');
        $resposta->assertJsonPath('aplicacao.fabricante', null);
        $resposta->assertJsonPath('proxima_dose', null);
    }
}
