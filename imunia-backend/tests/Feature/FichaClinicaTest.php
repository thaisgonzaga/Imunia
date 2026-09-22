<?php

namespace Tests\Feature;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Autorizacao;
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
 * 1. Sem autorização vigente, a ficha responde espécie, nome e código — e nada
 *    mais (RF35c). Nem o nome do tutor, nem a contagem de registros.
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
     * @param array<string, mixed> $atributos
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

    private function autorizar(Animal $animal, Prestador $prestador, string $estado = 'vigente'): Autorizacao
    {
        $factory = Autorizacao::factory();

        if ($estado !== 'vigente') {
            $factory = $factory->{$estado}();
        }

        return $factory->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $animal->tutor->user_id,
        ]);
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
        $this->autorizar($animal, $hospital);

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

    /* Sem autorização — o estado P2 -------------------------------------- */

    /**
     * RF35c — na ausência de autorização, nada além da identificação do animal.
     * O teste afirma pela negativa, que é como a regra é escrita: o que não pode
     * aparecer não aparece em canto algum da resposta.
     */
    public function test_sem_autorizacao_a_ficha_devolve_apenas_a_identificacao_do_animal(): void
    {
        $clinica = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Mel', ['especie' => 'gato']);

        $resposta = $this->abrirFicha($this->marcelo($clinica), $animal)
            ->assertOk()
            ->assertJsonPath('acesso', 'sem_autorizacao')
            ->assertJsonPath('animal.nome', 'Mel')
            ->assertJsonPath('animal.especie', 'gato')
            ->assertJsonPath('animal.codigo', $animal->codigo)
            ->assertJsonPath('autorizacao', null)

            // Os blocos da ficha não vêm vazios: não vêm.
            ->assertJsonMissingPath('carteira')
            ->assertJsonMissingPath('historico')
            ->assertJsonMissingPath('alertas')
            ->assertJsonMissingPath('resumo')
            ->assertJsonMissingPath('anexos')
            ->assertJsonMissingPath('animal.tutor');

        // RN12 — nem o nome do tutor, nem o do animal por via indireta, nem
        // data de nascimento: a resposta inteira não contém a palavra.
        $this->assertStringNotContainsString('Helena', $resposta->getContent());
    }

    public function test_autorizacao_expirada_nao_abre_a_ficha(): void
    {
        $clinica = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica, 'expirada');

        $this->abrirFicha($this->marcelo($clinica), $animal)
            ->assertOk()
            ->assertJsonPath('acesso', 'sem_autorizacao');
    }

    public function test_autorizacao_revogada_nao_abre_a_ficha(): void
    {
        $clinica = $this->clinica();
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica, 'revogada');

        $this->abrirFicha($this->marcelo($clinica), $animal)
            ->assertOk()
            ->assertJsonPath('acesso', 'sem_autorizacao');
    }

    /**
     * RN37 — a autorização é nominal e por prestador. A concedida à clínica
     * não vale no hospital, ainda que seja o mesmo profissional em ambos: é o
     * prestador que responde pela guarda do prontuário (decisoes.md §4.2).
     */
    public function test_autorizacao_de_outro_prestador_nao_vale_no_contexto_ativo(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($clinica, $hospital);

        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

        $this->abrirFicha($marcelo, $animal, ['prestador' => $hospital->id])
            ->assertOk()
            ->assertJsonPath('acesso', 'sem_autorizacao');
    }

    /* Log de acesso — RF52 ------------------------------------------------ */

    public function test_abrir_ficha_sem_autorizacao_fica_registrado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Mel');

        $this->abrirFicha($marcelo, $animal)->assertOk();

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $animal->tutor_id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::FICHA_SEM_AUTORIZACAO,
        ]);
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
        $this->autorizar($animal, $clinica);
        $this->atender($animal, $clinica, $marcelo);

        $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('acesso', 'autorizado')
            ->assertJsonPath('aviso_outro_prestador', false);

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    public function test_ver_registro_de_outro_prestador_fica_registrado_antes_da_exibicao(): void
    {
        $clinica = $this->clinica();
        $petCenter = $this->clinica('Pet Center Zona Sul');
        $marcelo = $this->marcelo($clinica);

        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);
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
        $this->autorizar($animal, $clinica);
        $this->atender($animal, $petCenter, $marcelo);

        $this->abrirFicha($marcelo, $animal)->assertOk();
        $this->abrirFicha($marcelo, $animal)->assertOk();

        $this->assertDatabaseCount('registros_de_acesso', 2);
    }

    /* Ficha autorizada ---------------------------------------------------- */

    public function test_a_ficha_autorizada_traz_as_quatro_abas_e_o_prazo_da_autorizacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $autorizacao = $this->autorizar($animal, $clinica);

        $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('acesso', 'autorizado')
            ->assertJsonPath('animal.tutor.nome', 'Helena Ramos')
            ->assertJsonPath('autorizacao.expira_em', $autorizacao->expira_em->toDateString())
            ->assertJsonPath('autorizacao.a_expirar', false)
            ->assertJsonStructure([
                'prestador' => ['id', 'nome'],
                'vinculos',
                'resumo' => ['contagens', 'proximas_doses', 'total_de_atendimentos'],
                'carteira' => ['resumo', 'proximas_doses', 'grupos'],
                'historico' => ['resumo', 'filtros', 'entradas'],
                'anexos',
                'alertas' => ['clinicos', 'administrativos'],
            ]);
    }

    /**
     * RN39 — noventa dias, renováveis. A quinze dias do fim, a ficha avisa, e o
     * texto diz o que o prestador pode fazer a respeito: pedir, nunca conceder
     * (RF36).
     */
    public function test_autorizacao_perto_do_fim_vira_alerta_administrativo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');

        $this->autorizar($animal, $clinica)
            ->forceFill(['expira_em' => now()->addDays(9)])
            ->save();

        $resposta = $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('autorizacao.a_expirar', true);

        $chaves = array_column($resposta->json('alertas.administrativos'), 'chave');
        $this->assertContains('autorizacao-a-expirar', $chaves);
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
        $this->autorizar($animal, $clinica);

        $resposta = $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('animal.preliminar', true);

        $preliminar = collect($resposta->json('alertas.administrativos'))
            ->firstWhere('chave', 'cadastro-preliminar');

        $this->assertNotNull($preliminar);
        $this->assertSame('Completar caracterização', $preliminar['acao']['rotulo']);
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
        $this->autorizar($animal, $clinica);
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
     * RF32c — o arquivo sai pela rota que confere a autorização, e o caminho no
     * armazenamento não aparece em resposta alguma. Uma URL pública assinada uma
     * vez continuaria valendo depois de revogado o acesso; esta não.
     */
    public function test_os_anexos_saem_pela_rota_clinica_e_nunca_pelo_caminho_do_arquivo(): void
    {
        Storage::fake('local');

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($animal, $clinica);

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

    public function test_o_anexo_e_recusado_sem_autorizacao_vigente(): void
    {
        Storage::fake('local');

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalDe('Helena Ramos', 'Théo');
        $autorizacao = $this->autorizar($animal, $clinica);

        $atendimento = $this->atender($animal, $clinica, $marcelo);
        $anexo = AnexoAtendimento::factory()->create([
            'atendimento_id' => $atendimento->id,
            'caminho' => 'anexos/raspado.png',
        ]);
        Storage::disk('local')->put('anexos/raspado.png', 'conteudo-de-teste');

        // A revogação alcança o arquivo já listado numa ficha aberta antes
        // dela: a conferência é a cada pedido, não a cada tela (RN40).
        $autorizacao->forceFill(['revogada_em' => now()])->save();

        $this->actingAs($marcelo)
            ->get("/api/clinica/animais/{$animal->codigo}/anexos/{$anexo->id}")
            ->assertForbidden();
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
        $this->autorizar($animal, $clinica);

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
        $this->autorizar($animal, $clinica);

        $animal->tutor->user->forceFill(['ativado_em' => null])->save();

        $resposta = $this->abrirFicha($marcelo, $animal)
            ->assertOk()
            ->assertJsonPath('animal.tutor.ativado', false);

        $chaves = array_column($resposta->json('alertas.administrativos'), 'chave');
        $this->assertContains('tutor-nao-ativado', $chaves);
    }
}
