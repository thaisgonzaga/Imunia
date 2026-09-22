<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Exportacao;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use App\Services\ExportacaoDeHistoricoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * T15 — exportar o histórico em PDF verificável (RF46), e o encontro dele com
 * a verificação pública que já existia (RF47): o documento emitido aqui é o
 * que P09 confirma lá.
 */
class ExportacaoDeHistoricoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

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

    private function vacinar(Animal $animal, array $atributos = []): Vacinacao
    {
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $protocolo = ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        return Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => Prestador::factory()->create()->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonths(2),
            'ordem_dose' => 1,
            ...$atributos,
        ]);
    }

    public function test_a_emissao_exige_sessao(): void
    {
        $this->postJson('/api/animais/IM-7F3K-92QD/exportacoes', ['conteudo' => 'historico'])
            ->assertUnauthorized();
    }

    public function test_usuario_sem_cadastro_de_tutor_nao_emite(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->postJson('/api/animais/IM-7F3K-92QD/exportacoes', ['conteudo' => 'historico'])
            ->assertForbidden();
    }

    // RN12 — mesma resposta para código inexistente e animal de outro tutor.
    public function test_animal_de_outro_tutor_responde_404(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animal = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);
        $this->vacinar($animal);

        $this->actingAs($helena->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'historico'])
            ->assertNotFound();
    }

    // RF46a — cada emissão possui identificador distinto e é registrada com
    // autor e data.
    public function test_a_emissao_registra_autor_data_e_identificador_proprio(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $this->vacinar($animal);

        $resposta = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'historico']);

        $resposta->assertCreated();

        $exportacao = Exportacao::sole();

        $this->assertSame($tutor->user->id, $exportacao->emitido_por);
        $this->assertSame($animal->id, $exportacao->animal_id);
        $this->assertSame('Théo', $exportacao->animal_nome);
        $this->assertNotNull($exportacao->emitido_em);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{16}$/', $exportacao->codigo);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $exportacao->resumo);

        $resposta->assertJsonPath('exportacao.codigo', $exportacao->codigo);
        $resposta->assertJsonPath('exportacao.resumo', $exportacao->resumoAbreviado());
        $resposta->assertJsonPath(
            'exportacao.url_documento',
            "/api/animais/{$animal->codigo}/exportacoes/{$exportacao->codigo}/documento",
        );

        Storage::disk('local')->assertExists($exportacao->caminhoDoArquivo());
    }

    public function test_duas_emissoes_do_mesmo_conteudo_tem_codigos_distintos(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $this->vacinar($animal);

        $primeira = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'carteira'])
            ->json('exportacao.codigo');
        $segunda = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'carteira'])
            ->json('exportacao.codigo');

        $this->assertNotSame($primeira, $segunda);

        // O conteúdo não mudou entre as duas: o resumo assina o conteúdo, e é
        // o mesmo — duas emissões do mesmo documento (RN47).
        $this->assertSame(1, Exportacao::query()->distinct('resumo')->count('resumo'));
    }

    // O documento emitido aqui é verificável na rota pública de RF47.
    public function test_o_documento_emitido_e_autentico_na_verificacao_publica(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->gato()->create(['tutor_id' => $tutor->id, 'nome' => 'Nina']);
        $this->vacinar($animal);

        $emissao = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'historico'])
            ->json('exportacao');

        $verificacao = $this->getJson(
            "/api/documentos/{$emissao['codigo']}?resumo={$emissao['resumo']}",
        );

        $verificacao->assertOk();
        $verificacao->assertJsonPath('situacao', 'autentico');
        $verificacao->assertJsonPath('animal.nome', 'Nina');
        $verificacao->assertJsonPath('animal.especie', 'gato');
    }

    public function test_sem_registros_nao_ha_documento(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'historico'])
            ->assertUnprocessable();

        $this->assertSame(0, Exportacao::query()->count());
    }

    public function test_o_recorte_de_periodo_sem_registros_nao_emite(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $this->vacinar($animal, ['aplicado_em' => now()->subMonths(20)]);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", [
                'conteudo' => 'historico',
                'meses' => 12,
            ])
            ->assertUnprocessable();

        // Sem o recorte, o mesmo registro emite normalmente.
        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'historico'])
            ->assertCreated();
    }

    public function test_a_carteira_nao_aceita_recorte_de_periodo(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $this->vacinar($animal);

        $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", [
                'conteudo' => 'carteira',
                'meses' => 12,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meses');
    }

    public function test_o_download_entrega_o_pdf_da_emissao(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $this->vacinar($animal);

        $emissao = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'carteira'])
            ->json('exportacao');

        $download = $this->actingAs($tutor->user)
            ->get("/api/animais/{$animal->codigo}/exportacoes/{$emissao['codigo']}/documento");

        $download->assertOk();
        $download->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $download->streamedContent());
    }

    // RN12, de novo: emissão de animal alheio responde 404 igual a inexistente.
    public function test_o_download_de_emissao_de_outro_tutor_responde_404(): void
    {
        $helena = $this->helena();
        $outro = Tutor::factory()->create(['nome' => 'Marcos Lima']);
        $animalAlheio = Animal::factory()->create(['tutor_id' => $outro->id, 'nome' => 'Bidu']);
        $this->vacinar($animalAlheio);

        $emissao = $this->actingAs($outro->user)
            ->postJson("/api/animais/{$animalAlheio->codigo}/exportacoes", ['conteudo' => 'carteira'])
            ->json('exportacao');

        $meuAnimal = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo']);

        $this->actingAs($helena->user)
            ->get("/api/animais/{$meuAnimal->codigo}/exportacoes/{$emissao['codigo']}/documento")
            ->assertNotFound();
    }

    // RF46b — o registro não verificado é assinalado como tal no documento.
    public function test_o_registro_pregresso_e_assinalado_como_nao_verificado(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        Vacinacao::factory()->pregresso()->semImunobiologico()->create([
            'animal_id' => $animal->id,
            'lancado_por_user_id' => $tutor->user->id,
            'aplicado_em' => now()->subMonths(3),
        ]);

        $emissao = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'carteira'])
            ->json('exportacao');

        $html = $this->htmlDaEmissao($emissao['codigo'], 'carteira');

        $this->assertStringContainsString('registro não verificado', $html);
        $this->assertStringContainsString('Informado pelo tutor', $html);
    }

    // RN46 — o rodapé adverte sobre a responsabilidade da difusão (RF46c).
    public function test_o_rodape_carrega_a_advertencia_e_o_codigo_da_emissao(): void
    {
        $tutor = $this->helena();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $this->vacinar($animal);

        $emissao = $this->actingAs($tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'historico'])
            ->json('exportacao');

        $html = $this->htmlDaEmissao($emissao['codigo'], 'historico');

        $this->assertStringContainsString('responsabilidade pela difusão passa a ser sua', $html);
        $this->assertStringContainsString($emissao['codigo_formatado'], $html);
        $this->assertStringContainsString($emissao['resumo'], $html);
        $this->assertStringContainsString($emissao['link_verificacao'], $html);
    }

    /**
     * O HTML que o dompdf imprimiu na emissão, remontado pela mesma fronteira
     * (`montarDocumento`): o PDF gravado comprime os streams de texto, e é
     * sobre estas palavras — as mesmas — que dá para afirmar alguma coisa.
     */
    private function htmlDaEmissao(string $codigo, string $conteudo): string
    {
        $exportacao = Exportacao::localizar($codigo);
        $servico = app(ExportacaoDeHistoricoService::class);

        return view('pdf.exportacao', [
            'documento' => $servico->montarDocumento($exportacao->animal, $conteudo, null),
            'exportacao' => $exportacao,
            'linkVerificacao' => $servico->linkDeVerificacao($exportacao),
            'qrCode' => 'data:image/png;base64,',
        ])->render();
    }
}
