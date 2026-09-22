<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\AutorizacaoRevogada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * T12 — minhas autorizações (RF41), com revogação (RF39) e renovação (RF40c).
 */
class MinhasAutorizacoesTest extends TestCase
{
    use RefreshDatabase;

    private function helena(): Tutor
    {
        $usuario = User::factory()->create([
            'name' => 'Helena Ramos',
            'email' => 'helena.ramos@example.com',
        ]);

        return Tutor::factory()->create(['user_id' => $usuario->id, 'nome' => 'Helena Ramos']);
    }

    private function animal(Tutor $tutor, string $nome, string $especie = 'cao'): Animal
    {
        return Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nome' => $nome,
            'especie' => $especie,
        ]);
    }

    private function prestador(string $nome, string $tipo = 'clinica'): Prestador
    {
        return Prestador::factory()->create([
            'nome' => $nome,
            'tipo' => $tipo,
            'municipio' => 'Viçosa',
            'uf' => 'MG',
        ]);
    }

    private function autorizar(Animal $animal, Prestador $prestador, string ...$estados): Autorizacao
    {
        $factory = Autorizacao::factory();

        foreach ($estados as $estado) {
            $factory = $factory->{$estado}();
        }

        return $factory->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $animal->tutor->user_id,
        ]);
    }

    public function test_a_relacao_exige_sessao(): void
    {
        $this->getJson('/api/autorizacoes')->assertUnauthorized();
    }

    public function test_quem_nao_e_tutor_nao_alcanca_a_relacao(): void
    {
        // O livro de consentimento é do titular. O veterinário vê o âmbito dele
        // em V01, e nunca a relação de quem autorizou o quê.
        $this->actingAs(User::factory()->create())
            ->getJson('/api/autorizacoes')
            ->assertForbidden();
    }

    public function test_a_relacao_agrupa_por_animal_com_data_prazo_e_situacao(): void
    {
        // RF41 — por animal, com data de concessão, prazo e situação. O
        // agrupamento é a tese da tela: a pergunta é "quem vê o Théo".
        $tutor = $this->helena();
        $theo = $this->animal($tutor, 'Théo');
        $nina = $this->animal($tutor, 'Nina', 'gato');

        $this->autorizar($theo, $this->prestador('Clínica Vet Amigo'));
        $this->autorizar($nina, $this->prestador('Dra. Larissa Prado', 'autonomo'), 'aExpirar');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/autorizacoes');

        $resposta->assertOk();

        // Nina antes de Théo: a ordem é a dos animais na relação do tutor.
        $resposta->assertJsonPath('grupos.0.animal.nome', 'Nina');
        $resposta->assertJsonPath('grupos.0.autorizacoes.0.situacao', 'a_expirar');
        $resposta->assertJsonPath('grupos.0.autorizacoes.0.prestador.nome', 'Dra. Larissa Prado');
        $resposta->assertJsonPath('grupos.0.autorizacoes.0.prestador.tipo_rotulo', 'Atendimento domiciliar');
        $resposta->assertJsonPath('grupos.0.autorizacoes.0.dias_restantes', 12);

        $resposta->assertJsonPath('grupos.1.animal.nome', 'Théo');
        $resposta->assertJsonPath('grupos.1.autorizacoes.0.situacao', 'vigente');
        $resposta->assertJsonPath(
            'grupos.1.autorizacoes.0.expira_em',
            now()->addDays(Autorizacao::PRAZO_DIAS)->toDateString(),
        );
        $resposta->assertJsonPath('grupos.1.autorizacoes.0.renovavel', true);
    }

    public function test_a_autorizacao_a_expirar_continua_contada_entre_as_vigentes(): void
    {
        // "A expirar" é recorte, não estado ao lado: o acesso ainda existe hoje,
        // e a aba das vigentes não pode negá-lo.
        $tutor = $this->helena();
        $nina = $this->animal($tutor, 'Nina', 'gato');

        $this->autorizar($nina, $this->prestador('Dra. Larissa Prado', 'autonomo'), 'aExpirar');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/autorizacoes');

        $resposta->assertJsonPath('abas.vigentes', 1);
        $resposta->assertJsonPath('abas.a_expirar', 1);
        $resposta->assertJsonPath('abas.encerradas', 0);
    }

    public function test_as_encerradas_permanecem_consultaveis(): void
    {
        // RF41b — expirada e revogada continuam na relação, com a data e sem
        // ação: RN41 conserva o registro de cada ato de vontade do tutor.
        $tutor = $this->helena();
        $theo = $this->animal($tutor, 'Théo');

        $this->autorizar($theo, $this->prestador('Clínica São Bento'), 'expirada');
        $this->autorizar($theo, $this->prestador('Pet Center Zona Sul'), 'revogada');

        $resposta = $this->actingAs($tutor->user)->getJson('/api/autorizacoes');

        $resposta->assertJsonPath('abas.vigentes', 0);
        $resposta->assertJsonPath('abas.encerradas', 2);

        $situacoes = collect($resposta->json('grupos.0.autorizacoes'))->pluck('situacao')->all();
        $this->assertEqualsCanonicalizing(['expirada', 'revogada'], $situacoes);

        $revogada = collect($resposta->json('grupos.0.autorizacoes'))
            ->firstWhere('situacao', 'revogada');

        $this->assertSame(now()->subDay()->toDateString(), $revogada['revogada_em']);
        $this->assertFalse($revogada['renovavel']);
    }

    public function test_revogar_encerra_o_acesso_do_prestador_na_hora(): void
    {
        // RF39a — efeito imediato, sem nova sessão do prestador: o âmbito de
        // RN48 deixa de alcançar o animal no mesmo instante.
        Notification::fake();

        $tutor = $this->helena();
        $theo = $this->animal($tutor, 'Théo');
        $clinica = $this->prestador('Clínica Vet Amigo');
        $autorizacao = $this->autorizar($theo, $clinica);

        $resposta = $this->actingAs($tutor->user)
            ->deleteJson("/api/autorizacoes/{$autorizacao->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('situacao', 'revogada');

        // A linha não é apagada (RN41): o que muda é a data que encerra a
        // vigência.
        $this->assertDatabaseHas('autorizacoes_acesso', ['id' => $autorizacao->id]);
        $this->assertNotNull($autorizacao->refresh()->revogada_em);
        $this->assertSame(0, Animal::query()->sobAutorizacaoVigenteDe($clinica)->count());
    }

    public function test_a_revogacao_e_comunicada_ao_prestador(): void
    {
        // RF39c — comunicada ao prestador, e não apenas registrada.
        Notification::fake();

        $tutor = $this->helena();
        $theo = $this->animal($tutor, 'Théo');
        $clinica = $this->prestador('Clínica Vet Amigo');

        $marcelo = User::factory()->create(['name' => 'Marcelo Andrade']);
        $clinica->usuarios()->attach($marcelo->id, ['papel' => 'veterinario']);

        $autorizacao = $this->autorizar($theo, $clinica);

        $this->actingAs($tutor->user)
            ->deleteJson("/api/autorizacoes/{$autorizacao->id}")
            ->assertOk();

        Notification::assertSentTo(
            $marcelo,
            fn (AutorizacaoRevogada $aviso) => $aviso->animal === 'Théo'
                && $aviso->prestador === 'Clínica Vet Amigo',
        );
    }

    public function test_revogar_de_novo_termina_no_mesmo_lugar(): void
    {
        Notification::fake();

        $tutor = $this->helena();
        $theo = $this->animal($tutor, 'Théo');
        $autorizacao = $this->autorizar($theo, $this->prestador('Clínica Vet Amigo'), 'revogada');
        $revogadaEm = $autorizacao->revogada_em;

        $this->actingAs($tutor->user)
            ->deleteJson("/api/autorizacoes/{$autorizacao->id}")
            ->assertOk()
            ->assertJsonPath('situacao', 'revogada');

        // A data do ato é a do primeiro, não a do toque repetido.
        $this->assertTrue($revogadaEm->equalTo($autorizacao->refresh()->revogada_em));
        Notification::assertNothingSent();
    }

    public function test_a_autorizacao_de_outro_tutor_nao_existe_para_este(): void
    {
        $tutor = $this->helena();
        $outro = Tutor::factory()->create(['user_id' => User::factory()->create()->id]);
        $bidu = $this->animal($outro, 'Bidu');
        $autorizacao = $this->autorizar($bidu, $this->prestador('Clínica Vet Amigo'));

        // 404 e não 403: a segunda resposta confirmaria que a linha existe.
        $this->actingAs($tutor->user)
            ->deleteJson("/api/autorizacoes/{$autorizacao->id}")
            ->assertNotFound();

        $this->assertNull($autorizacao->refresh()->revogada_em);
    }

    public function test_renovar_estende_o_prazo_sem_novo_codigo(): void
    {
        // RF40c — em ato único. Nenhuma confirmação por código: o consentimento
        // já foi manifestado uma vez.
        $tutor = $this->helena();
        $nina = $this->animal($tutor, 'Nina', 'gato');
        $autorizacao = $this->autorizar($nina, $this->prestador('Dra. Larissa Prado', 'autonomo'), 'aExpirar');

        $resposta = $this->actingAs($tutor->user)
            ->postJson("/api/autorizacoes/{$autorizacao->id}/renovar");

        $resposta->assertCreated();
        $resposta->assertJsonPath('situacao', 'renovada');
        $resposta->assertJsonPath(
            'renovacao.expira_em',
            now()->addDays(Autorizacao::PRAZO_DIAS)->toDateString(),
        );

        // RN41 — a renovação entra como linha nova, e a anterior permanece.
        $this->assertSame(2, Autorizacao::query()->where('animal_id', $nina->id)->count());
    }

    public function test_a_autorizacao_renovada_nao_reaparece_como_expirada(): void
    {
        // Renovar não interrompe acesso algum: anunciar a linha sucedida como
        // "expirada" contaria ao tutor uma queda que não houve.
        $tutor = $this->helena();
        $nina = $this->animal($tutor, 'Nina', 'gato');
        $autorizacao = $this->autorizar($nina, $this->prestador('Dra. Larissa Prado', 'autonomo'), 'aExpirar');

        $this->actingAs($tutor->user)
            ->postJson("/api/autorizacoes/{$autorizacao->id}/renovar")
            ->assertCreated();

        $resposta = $this->actingAs($tutor->user)->getJson('/api/autorizacoes');

        $resposta->assertJsonPath('abas.vigentes', 1);
        $resposta->assertJsonPath('abas.encerradas', 0);
        $resposta->assertJsonCount(1, 'grupos.0.autorizacoes');
        $resposta->assertJsonPath('grupos.0.autorizacoes.0.situacao', 'vigente');
    }

    public function test_autorizar_de_novo_depois_da_revogacao_conserva_a_linha_revogada(): void
    {
        // O oposto do caso acima: aqui houve interrupção, e RF41b manda
        // conservá-la à vista.
        $tutor = $this->helena();
        $theo = $this->animal($tutor, 'Théo');
        $petCenter = $this->prestador('Pet Center Zona Sul');

        $this->autorizar($theo, $petCenter, 'revogada');
        $this->autorizar($theo, $petCenter);

        $resposta = $this->actingAs($tutor->user)->getJson('/api/autorizacoes');

        $resposta->assertJsonPath('abas.vigentes', 1);
        $resposta->assertJsonPath('abas.encerradas', 1);
        $resposta->assertJsonCount(2, 'grupos.0.autorizacoes');
    }

    public function test_o_que_ja_terminou_nao_se_renova(): void
    {
        // Depois do prazo o consentimento acabou. Retomá-lo é o fluxo de T11,
        // com código (RF37) — que é o "Autorizar de novo" da tela.
        $tutor = $this->helena();
        $theo = $this->animal($tutor, 'Théo');
        $autorizacao = $this->autorizar($theo, $this->prestador('Clínica São Bento'), 'expirada');

        $this->actingAs($tutor->user)
            ->postJson("/api/autorizacoes/{$autorizacao->id}/renovar")
            ->assertStatus(409)
            ->assertJsonPath('situacao', 'encerrada');

        $this->assertSame(1, Autorizacao::query()->where('animal_id', $theo->id)->count());
    }
}
