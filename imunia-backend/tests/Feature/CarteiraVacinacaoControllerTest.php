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

class CarteiraVacinacaoControllerTest extends TestCase
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

    public function test_a_carteira_exige_sessao(): void
    {
        $this->getJson('/api/animais/IM-7F3K-92QD/carteira')->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_alcanca_a_carteira(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson('/api/animais/IM-7F3K-92QD/carteira')
            ->assertForbidden();
    }

    public function test_codigo_inexistente_responde_404(): void
    {
        $tutor = $this->helena();

        $this->actingAs($tutor->user)
            ->getJson('/api/animais/IM-0000-0000/carteira')
            ->assertNotFound();
    }

    // RN12 — mesma resposta para código inexistente e animal de outro tutor.
    public function test_animal_de_outro_tutor_responde_404(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $this->actingAs($helena->user)
            ->getJson("/api/animais/{$animal->codigo}/carteira")
            ->assertNotFound();
    }

    public function test_animal_sem_vacinacao_devolve_carteira_vazia(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/carteira");

        $resposta->assertOk();
        $resposta->assertJsonPath('grupos', []);
        $resposta->assertJsonPath('proximas_doses', []);
        $resposta->assertJsonPath('resumo.em_dia', 0);
        $resposta->assertJsonPath('resumo.atrasada', 0);
        $resposta->assertJsonPath('resumo.nao_verificadas', 0);
    }

    public function test_dose_com_reforco_vencido_aparece_como_atrasada(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $prestador = Prestador::factory()->create();

        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $protocolo = ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonths(14), // reforço anual: 2 meses atrasado
            'ordem_dose' => 1,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/carteira");

        $resposta->assertOk();
        $resposta->assertJsonPath('grupos.0.situacao.tipo', 'atrasada');
        $resposta->assertJsonPath('resumo.atrasada', 1);
        $resposta->assertJsonPath('proximas_doses.0.imunobiologico', $imunobiologico->nome_comercial);
    }

    public function test_dose_com_reforco_distante_aparece_como_em_dia(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $prestador = Prestador::factory()->create();

        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $protocolo = ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonth(), // reforço anual: daqui a 11 meses
            'ordem_dose' => 1,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/carteira");

        $resposta->assertOk();
        $resposta->assertJsonPath('grupos.0.situacao.tipo', 'em-dia');
        $resposta->assertJsonPath('resumo.em_dia', 1);
    }

    public function test_serie_primaria_incompleta_agenda_a_proxima_dose_pelo_intervalo(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nome' => 'Théo',
            'nascimento_em' => now()->subMonths(6)->toDateString(),
        ]);
        $prestador = Prestador::factory()->create();

        $imunobiologico = Imunobiologico::factory()->create();
        $protocolo = ProtocoloVacinal::factory()->create([
            'imunobiologico_id' => $imunobiologico->id,
            // O rótulo aparece na justificativa que o tutor lê (RF26b), e é por
            // isso que este teste o fixa em vez de aceitar o da factory.
            'versao_protocolo_id' => VersaoProtocolo::factory()->create(['rotulo' => 'WSAVA 2024.1'])->id,
        ]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subDays(10),
            'ordem_dose' => 1,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/carteira");

        $resposta->assertOk();
        $resposta->assertJsonPath('grupos.0.proxima_dose.regra_texto', 'Intervalo de 21 dias entre as doses — protocolo WSAVA 2024.1.');
        // 2ª dose, 21 dias após a 1ª (10 dias atrás): faltam 11 dias — "próxima".
        $resposta->assertJsonPath('grupos.0.situacao.tipo', 'proxima');
    }

    public function test_registro_pregresso_conta_no_resumo_de_nao_verificadas(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);

        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();

        Vacinacao::factory()->pregresso()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $imunobiologico->id,
            'aplicado_em' => '2024-01-01',
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/carteira");

        $resposta->assertOk();
        $resposta->assertJsonPath('resumo.nao_verificadas', 1);
        $resposta->assertJsonPath('grupos.0.aplicacoes.0.origem', 'pregresso');
        $resposta->assertJsonPath('grupos.0.aplicacoes.0.rotulo', 'Aplicação anterior');

        // Sem dose de data exata, não há como prever a próxima (RN25 — o
        // pregresso não é ato clínico, e a imprecisão da data não sustenta
        // cálculo algum).
        $resposta->assertJsonPath('grupos.0.proxima_dose', null);
    }

    public function test_carteira_exibe_vacina_propria_aplicada_por_qualquer_clinica(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id]);
        $clinica = Prestador::factory()->create();

        // Uma vacina do acervo próprio da clínica (A04). O escopo que a esconde
        // das demais é de *escolha*, e não de leitura: a dose é do animal, e o
        // tutor tem de ver o nome do que foi aplicado nele — e a data seguinte.
        $imunobiologico = Imunobiologico::factory()->create([
            'chave' => 'p'.$clinica->id.'-vacina-da-casa',
            'nome_comercial' => 'Vacina da casa',
        ]);
        $imunobiologico->forceFill(['prestador_id' => $clinica->id])->save();

        $protocolo = ProtocoloVacinal::factory()->create([
            'imunobiologico_id' => $imunobiologico->id,
            'versao_protocolo_id' => null,
            'numero_doses_serie_primaria' => 1,
            'periodicidade_revacinacao_meses' => 12,
        ]);

        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonths(2),
            'data_aproximada' => false,
            'ordem_dose' => 1,
        ]);

        $this->actingAs($tutor->user)
            ->getJson("/api/animais/{$animal->codigo}/carteira")
            ->assertOk()
            ->assertJsonPath('grupos.0.imunobiologico.nome', 'Vacina da casa')
            ->assertJsonPath(
                'grupos.0.proxima_dose.prevista_para',
                now()->subMonths(2)->addYear()->toDateString(),
            );
    }
}
