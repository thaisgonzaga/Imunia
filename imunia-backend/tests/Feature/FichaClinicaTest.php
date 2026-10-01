<?php

namespace Tests\Feature;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * V06 — ficha clínica do animal (RF19, RF35, RF52).
 *
 * Três garantias são o assunto destes testes, e as três são de conformidade:
 *
 * 1. O código alcança o animal: a ficha sai inteira para qualquer prestador
 *    que o tenha na mão, e abri-la põe o animal na carteira do prestador.
 * 2. A gravação do log precede a exibição (RF52b): a resposta que traz registro
 *    de outro prestador deixa a linha gravada, e a que não traz não deixa.
 * 3. O óbito registrado encerra o calendário (RF22a) sem esconder o histórico.
 */
class FichaClinicaTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function animalDe(string $nomeDoTutor, string $nome, array $atributos = []): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => $nomeDoTutor]);

        return Animal::factory()->caracterizado()->create([
            ...$atributos,
            'tutor_id' => $tutor->id,
            'nome' => $nome,
        ]);
    }

    private function vincular(Animal $animal, Prestador $prestador): void
    {
        $prestador->vincular($animal);
    }

    private function atender(Animal $animal, Prestador $prestador, User $profissional): Atendimento
    {
        return Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'profissional_user_id' => $profissional->id,
        ]);
    }

    private function abrirFicha(User $usuario, Animal $animal, array $extras = [])
    {
        return $this->actingAs($usuario)
            ->getJson("/api/clinica/animais/{$animal->codigo}?".http_build_query($extras));
    }

    /* Porta de entrada ----------------------------------------------------- */

    public function test_a_ficha_exige_sessao(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->getJson("/api/clinica/animais/{$animal->codigo}")->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_alcanca_a_ficha(): void
    {
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        // Nem mesmo o tutor do próprio animal: o ambiente clínico é de quem tem
        // vínculo de veterinário, e o tutor tem a tela dele (T04).
        $this->abrirFicha($animal->tutor->user, $animal)->assertForbidden();
    }

    public function test_prestador_sem_vinculo_com_o_profissional_e_recusado(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($animal, $hospital);

        $this->abrirFicha($this->marcelo($clinica), $animal, ['prestador' => $hospital->id])
            ->assertForbidden();
    }

    public function test_codigo_inexistente_responde_nao_encontrado(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->marcelo($clinica))
            ->getJson('/api/clinica/animais/IM-0000-0000')
            ->assertNotFound();
    }

    /* Alcance pelo código — o vínculo nasce da ficha ----------------------- */

    /**
     * O código é identificador exato: quem o tem na mão está com o animal à
     * frente, e o atendimento não espera pelo tutor. A ficha sai inteira para
     * o animal que a clínica nunca viu, e abri-la o põe na carteira dela.
     */
    public function test_animal_fora_da_carteira_e_alcancado_pelo_codigo_e_passa_a_ser_vinculado(): void
    {
        $clinica = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Mel', ['especie' => 'gato']);

        $this->assertFalse($clinica->acompanha($animal));

        $this->abrirFicha($this->marcelo($clinica), $animal)
            ->assertOk()
            ->assertJsonMissingPath('acesso')
            ->assertJsonPath('animal.nome', 'Mel')
            ->assertJsonPath('animal.especie', 'gato')
            ->assertJsonPath('animal.codigo', $animal->codigo)
            ->assertJsonPath('animal.tutor.nome', 'Helena Ramos')
            ->assertJsonPath('vinculo.desde', now()->toDateString())
            ->assertJsonPath('vinculo.origem', Prestador::VINCULO_POR_ATENDIMENTO)
            ->assertJsonMissingPath('autorizacao');

        $this->assertDatabaseHas('animal_prestador', [
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'origem' => Prestador::VINCULO_POR_ATENDIMENTO,
        ]);
    }

    /**
     * O vínculo é idempotente: reabrir a ficha não o recria, e a origem e a
     * data do primeiro ato — aqui, o cadastro pela clínica — continuam sendo
     * as que a ficha mostra.
     */
    public function test_reabrir_a_ficha_preserva_a_origem_e_a_data_do_vinculo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->travel(-10)->days();
        $clinica->vincular($animal, Prestador::VINCULO_POR_CADASTRO);
        $this->travelBack();

        $this->abrirFicha($marcelo, $animal)->assertOk();
        $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('vinculo.origem', Prestador::VINCULO_POR_CADASTRO)
            ->assertJsonPath('vinculo.desde', now()->subDays(10)->toDateString());

        $this->assertDatabaseCount('animal_prestador', 1);
    }

    /**
     * O vínculo é por prestador. O mesmo profissional, aberto o animal no
     * contexto do hospital, põe o animal na carteira do hospital — e a da
     * clínica, onde ele já estava, não muda.
     */
    public function test_abrir_a_ficha_em_outro_contexto_vincula_o_prestador_ativo(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($clinica, $hospital);

        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($animal, $clinica);

        $this->abrirFicha($marcelo, $animal, ['prestador' => $hospital->id])
            ->assertOk()
            ->assertJsonMissingPath('acesso')
            ->assertJsonPath('prestador.id', $hospital->id);

        $this->assertTrue($hospital->acompanha($animal));
        $this->assertTrue($clinica->acompanha($animal));
        $this->assertDatabaseCount('animal_prestador', 2);
    }

    /* Log de acesso — RF52 ------------------------------------------------ */

    /**
     * Abrir a ficha de um animal novo para a clínica não é, por si, acesso a
     * registro alheio: sem histórico de outro prestador, nenhuma linha.
     */
    public function test_abrir_ficha_de_animal_novo_sem_historico_alheio_nao_gera_log(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Mel');

        $this->abrirFicha($marcelo, $animal)->assertOk();

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /**
     * RF52 — o log é do histórico produzido por *outro* prestador. A ficha do
     * animal cujos registros são todos da própria clínica não gera linha
     * alguma: registrá-la diria ao tutor que houve acesso de terceiro onde não
     * houve (RF52c).
     */
    public function test_ficha_com_registros_apenas_do_proprio_prestador_nao_gera_log(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($animal, $clinica);
        $this->atender($animal, $clinica, $marcelo);

        $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonMissingPath('acesso')
            ->assertJsonPath('aviso_outro_prestador', false);

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    public function test_ver_registro_de_outro_prestador_fica_registrado_antes_da_exibicao(): void
    {
        $clinica = $this->clinica();
        $petCenter = $this->clinica('Pet Center Zona Sul');
        $marcelo = $this->marcelo($clinica);

        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($animal, $clinica);
        $this->atender($animal, $petCenter, $marcelo);

        $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('aviso_outro_prestador', true);

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
        ]);
    }

    /**
     * RF52a — o log é imutável, e cada visualização é um fato próprio. Duas
     * aberturas da mesma ficha são dois acessos, e não um atualizado: é isso
     * que permite ao tutor ver, em T14, quantas vezes olharam o animal dele.
     */
    public function test_cada_visualizacao_grava_a_sua_propria_linha(): void
    {
        $clinica = $this->clinica();
        $petCenter = $this->clinica('Pet Center Zona Sul');
        $marcelo = $this->marcelo($clinica);

        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($animal, $clinica);
        $this->atender($animal, $petCenter, $marcelo);

        $this->abrirFicha($marcelo, $animal)->assertOk();
        $this->abrirFicha($marcelo, $animal)->assertOk();

        $this->assertDatabaseCount('registros_de_acesso', 2);
    }

    /* Ficha completa ------------------------------------------------------ */

    public function test_a_ficha_traz_as_quatro_abas_e_o_vinculo_com_o_prestador(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($animal, $clinica);

        $resposta = $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonMissingPath('acesso')
            ->assertJsonPath('animal.tutor.nome', 'Helena Ramos')
            ->assertJsonPath('vinculo.desde', now()->toDateString())
            ->assertJsonPath('vinculo.origem', Prestador::VINCULO_POR_ATENDIMENTO)
            ->assertJsonMissingPath('autorizacao')
            ->assertJsonStructure([
                'prestador' => ['id', 'nome'],
                'vinculos',
                'vinculo' => ['desde', 'origem'],
                'resumo' => ['contagens', 'proximas_doses', 'total_de_atendimentos'],
                'carteira' => ['resumo', 'proximas_doses', 'grupos'],
                'historico' => ['resumo', 'filtros', 'entradas'],
                'anexos',
                'alertas' => ['clinicos', 'administrativos'],
            ]);

        // Não há prazo a vencer: o alerta de autorização a expirar saiu junto
        // com a autorização.
        $chaves = array_column($resposta->json('alertas.administrativos'), 'chave');
        $this->assertNotContains('autorizacao-a-expirar', $chaves);
    }

    /**
     * RN17 — o cadastro do tutor é preliminar até que um veterinário o
     * complete, e a ficha do profissional é onde a pendência precisa aparecer,
     * porque é ele quem pode resolvê-la (RF19).
     */
    public function test_cadastro_preliminar_vira_alerta_com_acao_de_caracterizar(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $tutor = Tutor::factory()->create(['nome' => 'Helena Ramos']);
        $animal = Animal::factory()->gato()->create(['tutor_id' => $tutor->id]);
        $this->vincular($animal, $clinica);

        $resposta = $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('animal.preliminar', true);

        $preliminar = collect($resposta->json('alertas.administrativos'))
            ->firstWhere('chave', 'cadastro-preliminar');

        $this->assertNotNull($preliminar);
        $this->assertSame('Completar caracterização', $preliminar['acao']['rotulo']);

        // Enquanto preliminar, não há bloco de caracterização a exibir — nem
        // "não informado" a atribuir a ninguém.
        $resposta->assertJsonPath('animal.caracterizacao', null);
    }

    /**
     * RF19 — a ficha é onde o veterinário relê o que registrou sobre o animal
     * e de onde parte para mantê-lo: raça, pelagem, situação reprodutiva e
     * micro-chip viajam com a assinatura de quem os escreveu (RF19c).
     */
    public function test_a_ficha_traz_a_caracterizacao_com_a_assinatura(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo', [
            'raca' => 'SRD',
            'pelagem' => 'caramelo',
            'situacao_reprodutiva' => 'castrado',
        ]);
        $animal->forceFill([
            'microchip' => '076000000000123',
            'caracterizado_por_user_id' => $marcelo->id,
        ])->save();
        $this->vincular($animal, $clinica);

        $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('animal.preliminar', false)
            ->assertJsonPath('animal.caracterizacao.raca', 'SRD')
            ->assertJsonPath('animal.caracterizacao.pelagem', 'caramelo')
            ->assertJsonPath('animal.caracterizacao.situacao_reprodutiva', 'castrado')
            ->assertJsonPath('animal.caracterizacao.microchip', '076000000000123')
            ->assertJsonPath('animal.caracterizacao.caracterizado_por', 'Marcelo Andrade')
            ->assertJsonPath('animal.caracterizacao.caracterizado_em', now()->toDateString());
    }

    /* Óbito — RF22 -------------------------------------------------------- */

    /**
     * RF22a e RF22b — nenhum cálculo de calendário e nenhuma cobrança de dose,
     * mas o histórico inteiro continua consultável. As duas metades da regra no
     * mesmo teste, porque é a combinação delas que a tela precisa desenhar.
     */
    public function test_obito_encerra_o_calendario_sem_esconder_o_historico(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Antônio Prado', 'Tobias');
        $this->vincular($animal, $clinica);
        $this->atender($animal, $clinica, $marcelo);

        $animal->forceFill([
            'obito_em' => now()->subMonth()->toDateString(),
            'obito_registrado_por_user_id' => $marcelo->id,
        ])->save();

        $resposta = $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('animal.obito.em', $animal->obito_em->toDateString())
            ->assertJsonPath('animal.obito.registrado_por', 'Marcelo Andrade');

        // O histórico permanece: um registro clínico não desaparece com a morte
        // do animal, e RN51 manda guardá-lo pelo prazo da norma profissional.
        $this->assertNotEmpty($resposta->json('historico.entradas'));

        // Nenhuma dose a cobrar de quem já morreu.
        $chaves = array_column($resposta->json('alertas.clinicos'), 'chave');
        $this->assertEmpty(array_filter($chaves, fn (string $chave) => str_starts_with($chave, 'dose-atrasada')));

        $administrativos = array_column($resposta->json('alertas.administrativos'), 'chave');
        $this->assertContains('obito', $administrativos);
    }

    /* Anexos — RF32 -------------------------------------------------------- */

    /**
     * RF32c — o arquivo sai pela rota clínica, sob a sessão do profissional, e
     * o caminho no armazenamento não aparece em resposta alguma.
     */
    public function test_os_anexos_saem_pela_rota_clinica_e_nunca_pelo_caminho_do_arquivo(): void
    {
        Storage::fake('local');

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($animal, $clinica);

        $atendimento = $this->atender($animal, $clinica, $marcelo);
        $anexo = AnexoAtendimento::factory()->create([
            'atendimento_id' => $atendimento->id,
            'caminho' => 'anexos/raspado.png',
        ]);
        Storage::disk('local')->put('anexos/raspado.png', 'conteudo-de-teste');

        $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('anexos.0.disponivel', true)
            ->assertJsonPath('anexos.0.url', "/api/clinica/animais/{$animal->codigo}/anexos/{$anexo->id}")
            ->assertJsonPath('anexos.0.atendimento.prestador', 'Clínica Vet Amigo')
            ->assertJsonMissing(['caminho' => 'anexos/raspado.png']);

        $this->actingAs($marcelo)
            ->get("/api/clinica/animais/{$animal->codigo}/anexos/{$anexo->id}")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * O anexo é alcançado pelo código como a ficha: a clínica que nunca abriu
     * o animal recebe o arquivo, e o pedido o põe na carteira dela.
     */
    public function test_o_anexo_de_animal_fora_da_carteira_e_entregue_e_vincula(): void
    {
        Storage::fake('local');

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $atendimento = $this->atender($animal, $clinica, $marcelo);
        $anexo = AnexoAtendimento::factory()->create([
            'atendimento_id' => $atendimento->id,
            'caminho' => 'anexos/raspado.png',
        ]);
        Storage::disk('local')->put('anexos/raspado.png', 'conteudo-de-teste');

        $this->assertFalse($clinica->acompanha($animal));

        $this->actingAs($marcelo)
            ->get("/api/clinica/animais/{$animal->codigo}/anexos/{$anexo->id}")
            ->assertOk();

        $this->assertDatabaseHas('animal_prestador', [
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
        ]);
    }

    /**
     * O anexo é do animal do endereço: o de outro animal responde 404, ainda
     * que exista.
     */
    public function test_o_anexo_de_outro_animal_nao_e_encontrado(): void
    {
        Storage::fake('local');

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $outro = $this->animalDe('Sofia Nunes', 'Pipoca');

        $atendimento = $this->atender($outro, $clinica, $marcelo);
        $anexo = AnexoAtendimento::factory()->create([
            'atendimento_id' => $atendimento->id,
            'caminho' => 'anexos/raspado.png',
        ]);
        Storage::disk('local')->put('anexos/raspado.png', 'conteudo-de-teste');

        $this->actingAs($marcelo)
            ->get("/api/clinica/animais/{$animal->codigo}/anexos/{$anexo->id}")
            ->assertNotFound();
    }

    /**
     * RN49 — abrir o laudo produzido por outro prestador é acesso a registro
     * alheio, e gera a sua linha. É literalmente o episódio de P6: o segundo
     * profissional lendo o exame que o primeiro pediu.
     */
    public function test_abrir_anexo_de_outro_prestador_fica_registrado(): void
    {
        Storage::fake('local');

        $clinica = $this->clinica();
        $petCenter = $this->clinica('Pet Center Zona Sul');
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($animal, $clinica);

        $atendimento = $this->atender($animal, $petCenter, $marcelo);
        $anexo = AnexoAtendimento::factory()->create([
            'atendimento_id' => $atendimento->id,
            'caminho' => 'anexos/laudo.pdf',
        ]);
        Storage::disk('local')->put('anexos/laudo.pdf', 'conteudo-de-teste');

        RegistroDeAcesso::query()->delete();

        $this->actingAs($marcelo)
            ->get("/api/clinica/animais/{$animal->codigo}/anexos/{$anexo->id}")
            ->assertOk();

        $this->assertDatabaseHas('registros_de_acesso', [
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
        ]);
    }

    /* Tutor não ativado — RF14b ------------------------------------------- */

    public function test_tutor_que_nao_ativou_o_acesso_e_sinalizado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Sofia Nunes', 'Pipoca');
        $this->vincular($animal, $clinica);

        $animal->tutor->user->forceFill(['ativado_em' => null])->save();

        $resposta = $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('animal.tutor.ativado', false);

        $chaves = array_column($resposta->json('alertas.administrativos'), 'chave');
        $this->assertContains('tutor-nao-ativado', $chaves);
    }
}
