<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Autorizacao;
use App\Models\Exportacao;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * V06 → T15 — exportar o histórico pelo ambiente clínico (RF46, ator
 * veterinário).
 *
 * Duas garantias são o assunto destes testes:
 *
 * 1. O âmbito é a autorização vigente do prestador ativo, não a titularidade:
 *    sem ela, 403 — na emissão e em cada download.
 * 2. A emissão cujo documento leva registro de outro prestador grava a linha
 *    de RN49 antes de existir; a que leva só registro próprio, não. E a
 *    pergunta é sobre o documento emitido, não sobre o animal: o recorte que
 *    deixa o registro alheio de fora não presta contas do que não saiu.
 */
class ExportacaoPelaClinicaTest extends TestCase
{
    use RefreshDatabase;

    private ?ProtocoloVacinal $protocolo = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function clinica(string $nome = 'Clínica Vet Amigo'): Prestador
    {
        return Prestador::factory()->create(['nome' => $nome]);
    }

    private function marcelo(Prestador ...$prestadores): User
    {
        $usuario = User::factory()->create(['name' => 'Marcelo Andrade']);

        foreach ($prestadores as $prestador) {
            $usuario->prestadores()->attach($prestador, [
                'papel' => 'veterinario',
                'crmv' => '12345',
                'crmv_uf' => 'MG',
            ]);
        }

        return $usuario;
    }

    private function animalDe(string $nomeDoTutor, string $nome): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => $nomeDoTutor]);

        return Animal::factory()->caracterizado()->create([
            'tutor_id' => $tutor->id,
            'nome' => $nome,
        ]);
    }

    private function autorizar(Animal $animal, Prestador $prestador): Autorizacao
    {
        return Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $animal->tutor->user_id,
        ]);
    }

    /**
     * O protocolo é um só por teste: `ImunobiologicoFactory` tem chave única, e
     * duas vacinações sem protocolo explícito estourariam a restrição — a
     * armadilha de fixture anotada na fatia de V07.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function vacinar(Animal $animal, Prestador $prestador, array $atributos = []): Vacinacao
    {
        $this->protocolo ??= ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => Imunobiologico::factory()->antirrabica()->create()->id,
        ]);

        return Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $this->protocolo->imunobiologico_id,
            'protocolo_vacinal_id' => $this->protocolo->id,
            'aplicado_em' => now()->subMonths(2),
            'ordem_dose' => 1,
            ...$atributos,
        ]);
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function emitir(User $usuario, Animal $animal, Prestador $prestador, array $corpo = [])
    {
        return $this->actingAs($usuario)->postJson(
            "/api/clinica/animais/{$animal->codigo}/exportacoes?prestador={$prestador->id}",
            $corpo === [] ? ['conteudo' => 'historico'] : $corpo,
        );
    }

    /* Porta de entrada ----------------------------------------------------- */

    public function test_a_emissao_exige_sessao(): void
    {
        $this->postJson('/api/clinica/animais/IM-7F3K-92QD/exportacoes', ['conteudo' => 'historico'])
            ->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_emite(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->actingAs($animal->tutor->user)
            ->postJson("/api/clinica/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'historico'])
            ->assertForbidden();
    }

    // O âmbito da porta clínica: autorização vigente, não titularidade. O 403
    // nomeia o caminho em vez de negar a existência — mesma régua de V07.
    public function test_sem_autorizacao_vigente_a_emissao_responde_403(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vacinar($animal, $clinica);

        $this->emitir($marcelo, $animal, $clinica)->assertForbidden();

        $this->assertSame(0, Exportacao::query()->count());
        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    public function test_autorizacao_expirada_nao_e_vigente(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vacinar($animal, $clinica);

        Autorizacao::factory()->expirada()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $animal->tutor->user_id,
        ]);

        $this->emitir($marcelo, $animal, $clinica)->assertForbidden();
    }

    /* Emissão --------------------------------------------------------------- */

    public function test_a_emissao_registra_o_profissional_e_aponta_o_download_para_a_porta_clinica(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);
        $this->vacinar($animal, $clinica);

        $resposta = $this->emitir($marcelo, $animal, $clinica);

        $resposta->assertCreated();

        $exportacao = Exportacao::sole();

        $this->assertSame($marcelo->id, $exportacao->emitido_por);
        $this->assertSame($animal->id, $exportacao->animal_id);
        Storage::disk('local')->assertExists($exportacao->caminhoDoArquivo());

        // O contexto viaja no endereço porque o download o reverifica: sem ele,
        // o pedido recairia no primeiro vínculo do profissional.
        $resposta->assertJsonPath(
            'exportacao.url_documento',
            "/api/clinica/animais/{$animal->codigo}/exportacoes/{$exportacao->codigo}/documento"
                ."?prestador={$clinica->id}",
        );
    }

    // RN47 — o resumo assina o conteúdo. Quem emite não muda o que o documento
    // afirma: a emissão do veterinário e a do tutor sobre o mesmo recorte são
    // duas emissões do mesmo documento.
    public function test_a_emissao_da_clinica_e_a_do_tutor_assinam_o_mesmo_conteudo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);
        $this->vacinar($animal, $clinica);

        $pelaClinica = $this->emitir($marcelo, $animal, $clinica)->json('exportacao');
        $peloTutor = $this->actingAs($animal->tutor->user)
            ->postJson("/api/animais/{$animal->codigo}/exportacoes", ['conteudo' => 'historico'])
            ->json('exportacao');

        $this->assertNotSame($pelaClinica['codigo'], $peloTutor['codigo']);
        $this->assertSame(1, Exportacao::query()->distinct('resumo')->count('resumo'));
    }

    public function test_sem_registros_no_recorte_responde_422_sem_emitir_nem_gravar_linha(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica('Clínica Boa Vista');
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        // O único registro é alheio e fica fora do recorte de 12 meses: o
        // documento não existe, e a linha de RN49 sobre ele também não pode
        // existir — não se presta contas do que não saiu.
        Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $outra->id,
            'atendido_em' => now()->subMonths(20),
        ]);

        $this->emitir($marcelo, $animal, $clinica, ['conteudo' => 'historico', 'meses' => 12])
            ->assertUnprocessable();

        $this->assertSame(0, Exportacao::query()->count());
        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /* RN49 — a linha do livro de acessos ------------------------------------ */

    public function test_o_documento_com_registro_alheio_grava_a_linha_de_rn49(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica('Clínica Boa Vista');
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);
        $this->vacinar($animal, $outra);

        $this->emitir($marcelo, $animal, $clinica)->assertCreated();

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $animal->tutor_id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::EXPORTACAO_DE_REGISTRO_ALHEIO,
        ]);
    }

    public function test_o_documento_so_com_registro_proprio_nao_grava_linha(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);
        $this->vacinar($animal, $clinica);

        $this->emitir($marcelo, $animal, $clinica)->assertCreated();

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    // O pregresso não vem de prestador algum (RN24): exportá-lo não é acesso a
    // registro de terceiro, e dizer o contrário contaria ao tutor, em T14, que
    // uma clínica leu o que ele mesmo escreveu.
    public function test_o_pregresso_do_tutor_nao_conta_como_registro_alheio(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);
        $this->vacinar($animal, $clinica);
        Vacinacao::factory()->pregresso()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $this->protocolo->imunobiologico_id,
            'aplicado_em' => now()->subMonths(4),
        ]);

        $this->emitir($marcelo, $animal, $clinica)->assertCreated();

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    // A pergunta é sobre o documento emitido, não sobre o animal: o recorte de
    // período que deixa o registro alheio de fora não presta contas dele.
    public function test_o_recorte_que_exclui_o_registro_alheio_nao_grava_linha(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica('Clínica Boa Vista');
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);
        $this->vacinar($animal, $clinica);

        Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $outra->id,
            'atendido_em' => now()->subMonths(20),
        ]);

        $this->emitir($marcelo, $animal, $clinica, ['conteudo' => 'historico', 'meses' => 6])
            ->assertCreated();
        $this->assertDatabaseCount('registros_de_acesso', 0);

        // Sem o recorte, o atendimento alheio entra no documento — e aí sim a
        // linha existe.
        $this->emitir($marcelo, $animal, $clinica)->assertCreated();
        $this->assertDatabaseHas('registros_de_acesso', [
            'natureza' => RegistroDeAcesso::EXPORTACAO_DE_REGISTRO_ALHEIO,
        ]);
    }

    // A carteira leva todas as aplicações, mas atendimento nenhum: a vacinação
    // alheia grava a linha, o atendimento alheio não.
    public function test_a_carteira_presta_contas_de_vacinacao_alheia_e_nao_de_atendimento(): void
    {
        $clinica = $this->clinica();
        $outra = $this->clinica('Clínica Boa Vista');
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);
        $this->vacinar($animal, $clinica);

        Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $outra->id,
        ]);

        $this->emitir($marcelo, $animal, $clinica, ['conteudo' => 'carteira'])->assertCreated();
        $this->assertDatabaseCount('registros_de_acesso', 0);

        $this->vacinar($animal, $outra, ['ordem_dose' => 2]);

        $this->emitir($marcelo, $animal, $clinica, ['conteudo' => 'carteira'])->assertCreated();
        $this->assertDatabaseHas('registros_de_acesso', [
            'natureza' => RegistroDeAcesso::EXPORTACAO_DE_REGISTRO_ALHEIO,
        ]);
    }

    /* Download --------------------------------------------------------------- */

    public function test_o_download_reverifica_a_autorizacao_a_cada_pedido(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $autorizacao = $this->autorizar($animal, $clinica);
        $this->vacinar($animal, $clinica);

        $url = $this->emitir($marcelo, $animal, $clinica)->json('exportacao.url_documento');

        $this->actingAs($marcelo)->get($url)->assertOk();

        // A autorização revogada entre emitir e baixar fecha o arquivo — a
        // mesma regra do anexo (RF32c).
        $autorizacao->update(['revogada_em' => now()]);

        $this->actingAs($marcelo)->get($url)->assertForbidden();
    }

    public function test_a_emissao_de_outro_animal_responde_404(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $vizinho = $this->animalDe('Marcos Lima', 'Bidu');
        $this->autorizar($animal, $clinica);
        $this->autorizar($vizinho, $clinica);
        $this->vacinar($animal, $clinica);

        $codigo = $this->emitir($marcelo, $animal, $clinica)->json('exportacao.codigo');

        $this->actingAs($marcelo)
            ->get("/api/clinica/animais/{$vizinho->codigo}/exportacoes/{$codigo}/documento?prestador={$clinica->id}")
            ->assertNotFound();
    }
}
