<?php

namespace Tests\Feature;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricoConsolidadoControllerTest extends TestCase
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

    public function test_o_historico_exige_sessao(): void
    {
        $this->getJson('/api/animais/IM-7F3K-92QD/historico')->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_alcanca_o_historico(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson('/api/animais/IM-7F3K-92QD/historico')
            ->assertForbidden();
    }

    public function test_codigo_inexistente_responde_404(): void
    {
        $tutor = $this->helena();

        $this->actingAs($tutor->user)
            ->getJson('/api/animais/IM-0000-0000/historico')
            ->assertNotFound();
    }

    // RN12 — mesma resposta para código inexistente e animal de outro tutor.
    public function test_animal_de_outro_tutor_responde_404(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);

        $this->actingAs($helena->user)
            ->getJson("/api/animais/{$animal->codigo}/historico")
            ->assertNotFound();
    }

    public function test_animal_sem_registro_devolve_historico_vazio(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/historico");

        $resposta->assertOk();
        $resposta->assertJsonPath('entradas', []);
        $resposta->assertJsonPath('resumo.total', 0);
        $resposta->assertJsonPath('resumo.prestadores', 0);
        $resposta->assertJsonPath('resumo.desde', null);
        $resposta->assertJsonPath('filtros.tipos', []);
        $resposta->assertJsonPath('filtros.prestadores', []);
    }

    public function test_as_entradas_saem_da_mais_recente_para_a_mais_antiga(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $imunobiologico = Imunobiologico::factory()->create();
        $protocolo = ProtocoloVacinal::factory()->create(['imunobiologico_id' => $imunobiologico->id]);

        foreach (['2025-11-15', '2025-12-06', '2025-12-26'] as $indice => $data) {
            Vacinacao::factory()->create([
                'animal_id' => $animal->id,
                'imunobiologico_id' => $imunobiologico->id,
                'protocolo_vacinal_id' => $protocolo->id,
                'aplicado_em' => "{$data} 09:30:00",
                'ordem_dose' => $indice + 1,
            ]);
        }

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/historico");

        $resposta->assertOk();
        $resposta->assertJsonCount(3, 'entradas');
        $resposta->assertJsonPath('entradas.0.data', '2025-12-26');
        $resposta->assertJsonPath('entradas.1.data', '2025-12-06');
        $resposta->assertJsonPath('entradas.2.data', '2025-11-15');
        // O rótulo da dose é o mesmo que a carteira (T05) mostra: uma fonte só.
        $resposta->assertJsonPath('entradas.0.titulo', 'V10 múltipla canina · 3ª dose · final da série');
        $resposta->assertJsonPath('entradas.2.titulo', 'V10 múltipla canina · 1ª dose');
    }

    // RN25 — o registro pregresso vale pelo que afirma e pelo que declara não
    // saber; o resumo diz os campos ausentes em vez de omiti-los.
    public function test_registro_pregresso_declara_o_que_nao_sabe_e_nao_tem_prestador(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();

        Vacinacao::factory()->pregresso()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $imunobiologico->id,
            'aplicado_em' => '2024-06-01 00:00:00',
            'lancado_por_user_id' => $tutor->user->id,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/historico");

        $resposta->assertOk();
        $resposta->assertJsonPath('entradas.0.origem', 'pregresso');
        $resposta->assertJsonPath('entradas.0.data_aproximada', true);
        // O rótulo "Aplicação anterior" fica fora do título: é o que o chip de
        // procedência já diz, e repeti-lo enfraqueceria a marca (RN24).
        $resposta->assertJsonPath('entradas.0.titulo', 'Antirrábica');
        $resposta->assertJsonPath('entradas.0.resumo', 'Fabricante, lote e validade não informados.');
        $resposta->assertJsonPath('entradas.0.prestador.chave', 'sem-prestador');
        $resposta->assertJsonPath('entradas.0.prestador.rotulo', 'Lançado pelo tutor');
        $resposta->assertJsonPath('entradas.0.lancado_por.nome', 'Helena Ramos');
        // Pregresso não conta como prestador: ninguém assumiu responsabilidade
        // técnica por este registro.
        $resposta->assertJsonPath('resumo.prestadores', 0);
        $resposta->assertJsonPath('resumo.desde', '2024-06-01');
        $resposta->assertJsonPath('resumo.desde_aproximada', true);
    }

    public function test_apenas_a_entrada_mais_recente_do_grupo_anuncia_a_proxima_dose(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $protocolo = ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        foreach (['2024-06-23', '2025-06-23'] as $data) {
            Vacinacao::factory()->create([
                'animal_id' => $animal->id,
                'imunobiologico_id' => $imunobiologico->id,
                'protocolo_vacinal_id' => $protocolo->id,
                'aplicado_em' => "{$data} 09:30:00",
                'fabricante' => 'Zoetis',
                'lote' => "R71-90{$data[3]}0",
                'validade' => '2027-09-30',
            ]);
        }

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/historico");

        $resposta->assertOk();
        // Reforço anual contado da última aplicação: 23/06/2026.
        $this->assertStringContainsString(
            'Próxima dose: Reforço anual em 23/06/2026.',
            $resposta->json('entradas.0.resumo')
        );
        $this->assertStringContainsString('Fabricante Zoetis', $resposta->json('entradas.0.resumo'));
        // A dose anterior não repete a previsão — a mesma data em duas entradas
        // pareceria duas doses previstas.
        $this->assertStringNotContainsString('Próxima dose', $resposta->json('entradas.1.resumo'));
    }

    // RF35 — a linha do tempo é única e reúne as fontes: a aplicação de vacina
    // e o atendimento entram nela na mesma ordem cronológica.
    public function test_o_atendimento_e_a_sua_retificacao_entram_na_linha_do_tempo(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $prestador = Prestador::factory()->create(['nome' => 'Clínica Vet Amigo']);

        $atendimento = Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
        ]);
        AnexoAtendimento::factory()->count(3)->create(['atendimento_id' => $atendimento->id]);

        $retificacao = Atendimento::factory()
            ->retificando($atendimento)
            ->create(['atendido_em' => '2025-11-15 09:40:00']);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/historico");

        $resposta->assertOk();
        $resposta->assertJsonCount(2, 'entradas');

        $resposta->assertJsonPath('entradas.0.tipo', 'retificacao');
        // A correção abre na mesma tela do registro que corrige (T08): é um
        // atendimento, ainda que a linha do tempo a mostre como retificação.
        $resposta->assertJsonPath('entradas.0.registro', 'atendimento');
        $resposta->assertJsonPath('entradas.0.id', $retificacao->id);
        $resposta->assertJsonPath('entradas.0.titulo', 'Retificação · dermatite');
        // O vínculo é o que permite à tela desenhar o conector entre a correção
        // e o registro corrigido, em vez de deixá-la solta na cronologia.
        $resposta->assertJsonPath('entradas.0.vinculada_a', $atendimento->id);
        $this->assertStringContainsString(
            'As duas versões continuam visíveis.',
            $resposta->json('entradas.0.resumo')
        );

        $resposta->assertJsonPath('entradas.1.tipo', 'atendimento');
        $resposta->assertJsonPath('entradas.1.titulo', 'Atendimento · dermatite');
        $resposta->assertJsonPath('entradas.1.data', '2025-11-12');
        $resposta->assertJsonPath('entradas.1.anexos', 3);
        $resposta->assertJsonPath('entradas.1.vinculada_a', null);
        $resposta->assertJsonPath('entradas.1.aplicador.prestador', 'Clínica Vet Amigo');

        // Os dois tipos passam a existir no filtro, porque agora há registro de
        // cada um; a contagem de prestadores não dobra por serem da mesma clínica.
        $resposta->assertJsonCount(2, 'filtros.tipos');
        $resposta->assertJsonPath('filtros.tipos.0.chave', 'atendimento');
        $resposta->assertJsonPath('filtros.tipos.1.chave', 'retificacao');
        $resposta->assertJsonPath('resumo.prestadores', 1);
    }

    public function test_os_filtros_trazem_apenas_os_tipos_e_prestadores_presentes(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $prestador = Prestador::factory()->create(['nome' => 'Clínica Vet Amigo']);
        $imunobiologico = Imunobiologico::factory()->create();
        $protocolo = ProtocoloVacinal::factory()->create(['imunobiologico_id' => $imunobiologico->id]);

        Vacinacao::factory()->count(2)->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => '2025-12-06 09:30:00',
        ]);

        Vacinacao::factory()->pregresso()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => Imunobiologico::factory()->antirrabica()->create()->id,
            'aplicado_em' => '2024-06-01 00:00:00',
            'lancado_por_user_id' => $tutor->user->id,
        ]);

        $resposta = $this->actingAs($tutor->user)->getJson("/api/animais/{$animal->codigo}/historico");

        $resposta->assertOk();
        // Vacinação é o único tipo com registro: atendimento, exame, retificação
        // e óbito só entram nas fatias que os criarem, e um filtro que não
        // filtra nada é ruído na tela.
        $resposta->assertJsonCount(1, 'filtros.tipos');
        $resposta->assertJsonPath('filtros.tipos.0.chave', 'vacinacao');
        $resposta->assertJsonPath('filtros.tipos.0.rotulo', 'Vacinação');
        $resposta->assertJsonPath('filtros.tipos.0.total', 3);

        $resposta->assertJsonCount(2, 'filtros.prestadores');
        $resposta->assertJsonPath('filtros.prestadores.0.rotulo', 'Clínica Vet Amigo');
        $resposta->assertJsonPath('filtros.prestadores.0.total', 2);
        $resposta->assertJsonPath('filtros.prestadores.1.rotulo', 'Lançado pelo tutor');
        $resposta->assertJsonPath('filtros.prestadores.1.total', 1);
        $resposta->assertJsonPath('resumo.total', 3);
        $resposta->assertJsonPath('resumo.prestadores', 1);
    }
}
