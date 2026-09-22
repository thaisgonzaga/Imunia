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

/**
 * X02 — versões do cálculo de calendário (RF24). Cobre o ciclo inteiro da
 * versão (abrir rascunho, editar, publicar, encerrar a anterior), a checagem de
 * coerência que impede a publicação sem impedir a edição, o simulador dos
 * quatro casos declarados em RNF01 e — o teste que sustenta a promessa central
 * do requisito — RN32: publicar não recalcula data já emitida.
 */
class ProtocolosVacinaisControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->adminPlataforma()->create();
    }

    /**
     * Uma versão vigente com parâmetros de um imunobiológico — o ponto de
     * partida de quase todo caminho desta tela.
     *
     * @return array{0: VersaoProtocolo, 1: Imunobiologico, 2: ProtocoloVacinal}
     */
    private function cenarioVigente(): array
    {
        $versao = VersaoProtocolo::factory()->create(['rotulo' => '2024.1']);
        $imunobiologico = Imunobiologico::factory()->create(['nome_comercial' => 'V10 múltipla canina']);
        $protocolo = ProtocoloVacinal::factory()->create([
            'imunobiologico_id' => $imunobiologico->id,
            'versao_protocolo_id' => $versao->id,
        ]);

        return [$versao, $imunobiologico, $protocolo];
    }

    /**
     * @param  array<string, mixed>  $sobrescreve
     * @return array<string, mixed>
     */
    private function parametros(Imunobiologico $imunobiologico, array $sobrescreve = []): array
    {
        return [...[
            'imunobiologico_id' => $imunobiologico->id,
            'numero_doses_serie_primaria' => 3,
            'intervalo_minimo_dias' => 14,
            'intervalo_maximo_dias' => 28,
            'idade_minima_primeira_dose_semanas' => 6,
            'idade_minima_dose_final_semanas' => 16,
            'reforco_inicial_meses' => null,
            'periodicidade_revacinacao_meses' => 12,
            'limite_atraso_dias' => 30,
            'conduta_apos_limite' => 'prosseguir',
            'doses_adulto_sem_historico' => 2,
        ], ...$sobrescreve];
    }

    public function test_visitante_nao_autenticado_nao_alcanca_os_protocolos(): void
    {
        $this->getJson('/api/plataforma/protocolos')->assertUnauthorized();
    }

    public function test_usuario_sem_o_papel_recebe_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/plataforma/protocolos')
            ->assertForbidden();
    }

    public function test_usuario_sem_o_papel_nao_publica(): void
    {
        [$versao] = $this->cenarioVigente();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/plataforma/protocolos/versoes/{$versao->id}/publicar")
            ->assertForbidden();
    }

    public function test_listagem_traz_versoes_parametros_e_contagem_de_calculos(): void
    {
        [$versao, $imunobiologico, $protocolo] = $this->cenarioVigente();

        $animal = Animal::factory()->create(['tutor_id' => Tutor::factory()->create()->id]);
        Vacinacao::factory()->count(2)->create([
            'animal_id' => $animal->id,
            'prestador_id' => Prestador::factory()->create()->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
        ]);

        $resposta = $this->actingAs($this->admin())->getJson('/api/plataforma/protocolos');

        $resposta->assertOk();
        $resposta->assertJsonPath('versoes.0.rotulo', '2024.1');
        $resposta->assertJsonPath('versoes.0.situacao', 'vigente');
        $resposta->assertJsonPath('versoes.0.calculos', 2);
        $resposta->assertJsonPath('versoes.0.parametros.0.intervalo_previsto_dias', 21);
        $this->assertCount(4, $resposta->json('casos'));
    }

    public function test_rascunho_nasce_como_copia_da_versao_vigente(): void
    {
        [$versao, $imunobiologico] = $this->cenarioVigente();

        $resposta = $this->actingAs($this->admin())->postJson('/api/plataforma/protocolos/versoes', [
            'rotulo' => '2026.1',
            'base' => 'WSAVA 2024, com a revisão de intervalo mínimo da série felina.',
        ]);

        $resposta->assertCreated();
        $resposta->assertJsonPath('versao.situacao', 'rascunho');
        $resposta->assertJsonPath('versao.parametros.0.imunobiologico_id', $imunobiologico->id);
        $resposta->assertJsonPath('versao.parametros.0.intervalo_minimo_dias', 14);

        // A cópia é linha nova: editá-la não pode alcançar a versão vigente.
        $this->assertNotSame(
            $versao->protocolos()->value('id'),
            $resposta->json('versao.parametros.0.id'),
        );
    }

    public function test_nao_existem_dois_rascunhos_ao_mesmo_tempo(): void
    {
        $this->cenarioVigente();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/plataforma/protocolos/versoes', ['rotulo' => '2026.1'])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/plataforma/protocolos/versoes', ['rotulo' => '2026.2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rotulo');
    }

    public function test_rotulo_de_versao_nao_se_repete(): void
    {
        [$versao] = $this->cenarioVigente();

        $this->actingAs($this->admin())
            ->postJson('/api/plataforma/protocolos/versoes', ['rotulo' => $versao->rotulo])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rotulo');
    }

    public function test_parametros_sao_gravados_no_rascunho(): void
    {
        $rascunho = VersaoProtocolo::factory()->rascunho()->create(['rotulo' => '2026.1']);
        $imunobiologico = Imunobiologico::factory()->create();

        $resposta = $this->actingAs($this->admin())
            ->postJson("/api/plataforma/protocolos/versoes/{$rascunho->id}/parametros", $this->parametros($imunobiologico, [
                'intervalo_minimo_dias' => 21,
            ]));

        $resposta->assertOk();
        $resposta->assertJsonPath('parametros.intervalo_minimo_dias', 21);
        $resposta->assertJsonPath('incoerencias', []);

        $this->assertDatabaseHas('protocolos_vacinais', [
            'versao_protocolo_id' => $rascunho->id,
            'imunobiologico_id' => $imunobiologico->id,
            'intervalo_minimo_dias' => 21,
        ]);
    }

    public function test_versao_publicada_nao_e_editada(): void
    {
        [$versao, $imunobiologico] = $this->cenarioVigente();

        $this->actingAs($this->admin())
            ->postJson("/api/plataforma/protocolos/versoes/{$versao->id}/parametros", $this->parametros($imunobiologico))
            ->assertUnprocessable();
    }

    public function test_parametros_incoerentes_sao_gravados_mas_barram_a_publicacao(): void
    {
        $rascunho = VersaoProtocolo::factory()->rascunho()->create(['rotulo' => '2026.1']);
        $imunobiologico = Imunobiologico::factory()->create();
        $admin = $this->admin();

        // Intervalo de 400 dias com reforço em 12 meses: a série primária nunca
        // terminaria antes do reforço.
        $resposta = $this->actingAs($admin)
            ->postJson("/api/plataforma/protocolos/versoes/{$rascunho->id}/parametros", $this->parametros($imunobiologico, [
                'intervalo_minimo_dias' => 365,
                'intervalo_maximo_dias' => 435,
            ]));

        $resposta->assertOk();
        $this->assertCount(1, $resposta->json('incoerencias'));
        $this->assertStringContainsString('nunca terminaria antes do reforço', $resposta->json('incoerencias.0.mensagem'));

        // O rascunho continua salvo — é a publicação que fica indisponível.
        $this->assertDatabaseHas('protocolos_vacinais', [
            'versao_protocolo_id' => $rascunho->id,
            'intervalo_minimo_dias' => 365,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/plataforma/protocolos/versoes/{$rascunho->id}/publicar")
            ->assertUnprocessable();

        $this->assertSame('rascunho', $rascunho->fresh()->situacao);
    }

    public function test_publicar_encerra_a_versao_anterior(): void
    {
        [$vigente] = $this->cenarioVigente();
        $admin = $this->admin();

        $criacao = $this->actingAs($admin)->postJson('/api/plataforma/protocolos/versoes', ['rotulo' => '2026.1']);
        $rascunho = VersaoProtocolo::find($criacao->json('versao.id'));

        $resposta = $this->actingAs($admin)
            ->postJson("/api/plataforma/protocolos/versoes/{$rascunho->id}/publicar");

        $resposta->assertOk();
        $resposta->assertJsonPath('versao.situacao', 'vigente');

        $this->assertSame('encerrada', $vigente->fresh()->situacao);
        $this->assertNotNull($vigente->fresh()->encerrado_em);
        $this->assertNotNull($rascunho->fresh()->publicado_em);

        // Uma só versão vigente, sempre.
        $this->assertSame(1, VersaoProtocolo::where('situacao', 'vigente')->count());
    }

    /**
     * RN32, RF24b — o teste que sustenta a promessa do requisito: a data
     * prevista que o tutor viu antes da publicação continua a mesma depois
     * dela, calculada pelos parâmetros da versão à época.
     */
    public function test_publicar_nao_recalcula_data_ja_emitida(): void
    {
        [, $imunobiologico, $protocoloAntigo] = $this->cenarioVigente();

        $usuario = User::factory()->create(['name' => 'Helena Ramos']);
        $tutor = Tutor::factory()->create(['user_id' => $usuario->id]);
        $animal = Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nascimento_em' => now()->subMonths(3)->toDateString(),
        ]);

        $aplicacao = Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => Prestador::factory()->create()->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocoloAntigo->id,
            'aplicado_em' => now()->subDays(5),
            'ordem_dose' => 1,
        ]);

        $previstaAntes = $this->actingAs($usuario)
            ->getJson("/api/animais/{$animal->codigo}/carteira")
            ->json('grupos.0.proxima_dose.prevista_para');

        // A versão nova dobra o intervalo entre doses da série.
        $admin = $this->admin();
        $criacao = $this->actingAs($admin)->postJson('/api/plataforma/protocolos/versoes', ['rotulo' => '2026.1']);
        $rascunho = VersaoProtocolo::find($criacao->json('versao.id'));

        $this->actingAs($admin)->postJson("/api/plataforma/protocolos/versoes/{$rascunho->id}/parametros", $this->parametros($imunobiologico, [
            'intervalo_minimo_dias' => 40,
            'intervalo_maximo_dias' => 44,
        ]))->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/plataforma/protocolos/versoes/{$rascunho->id}/publicar")
            ->assertOk();

        $depois = $this->actingAs($usuario)->getJson("/api/animais/{$animal->codigo}/carteira");

        $depois->assertJsonPath('grupos.0.proxima_dose.prevista_para', $previstaAntes);

        // E o registro continua apontando para a linha de parâmetros de então.
        $this->assertSame($protocoloAntigo->id, $aplicacao->fresh()->protocolo_vacinal_id);
        $this->assertSame('2024.1', $aplicacao->fresh()->protocoloVacinal->versao);
    }

    public function test_descartar_apaga_o_rascunho_e_nao_a_versao_publicada(): void
    {
        [$vigente] = $this->cenarioVigente();
        $admin = $this->admin();

        $criacao = $this->actingAs($admin)->postJson('/api/plataforma/protocolos/versoes', ['rotulo' => '2026.1']);
        $rascunhoId = $criacao->json('versao.id');

        $this->actingAs($admin)
            ->deleteJson("/api/plataforma/protocolos/versoes/{$rascunhoId}")
            ->assertOk();

        $this->assertDatabaseMissing('versoes_protocolo', ['id' => $rascunhoId]);
        $this->assertDatabaseMissing('protocolos_vacinais', ['versao_protocolo_id' => $rascunhoId]);

        $this->actingAs($admin)
            ->deleteJson("/api/plataforma/protocolos/versoes/{$vigente->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('versoes_protocolo', ['id' => $vigente->id]);
    }

    public function test_simulador_percorre_a_serie_primaria_completa(): void
    {
        [$versao, $imunobiologico] = $this->cenarioVigente();

        $resposta = $this->actingAs($this->admin())->postJson('/api/plataforma/protocolos/simular', [
            'versao_id' => $versao->id,
            'imunobiologico_id' => $imunobiologico->id,
            'caso' => 'serie_completa',
        ]);

        $resposta->assertOk();

        $passos = collect($resposta->json('simulacao.passos'));

        $this->assertSame('1ª dose', $passos[0]['rotulo']);
        $this->assertStringContainsString('idade mínima de 6 semanas atendida', $passos[0]['regra']);

        // Sem atraso algum e com a série terminando acima da idade mínima, a
        // dose adicional de RN33 não se aplica — e o simulador diz isso em vez
        // de omitir a linha.
        $adicional = $passos->firstWhere('rotulo', 'Dose adicional');
        $this->assertSame('não se aplica', $adicional['valor_texto']);

        $this->assertSame('reforco', $passos->last()['tom']);
        $this->assertStringContainsString('Reforço anual', $passos->last()['rotulo']);
        $this->assertNull($passos->firstWhere('rotulo', 'Atraso'));
    }

    public function test_simulador_aponta_atraso_acima_do_limite_e_a_conduta_sugerida(): void
    {
        [$versao, $imunobiologico] = $this->cenarioVigente();

        $resposta = $this->actingAs($this->admin())->postJson('/api/plataforma/protocolos/simular', [
            'versao_id' => $versao->id,
            'imunobiologico_id' => $imunobiologico->id,
            'caso' => 'serie_com_atraso',
        ]);

        $resposta->assertOk();

        $atraso = collect($resposta->json('simulacao.passos'))->firstWhere('rotulo', 'Atraso');

        $this->assertNotNull($atraso);
        $this->assertSame('61 dias', $atraso['valor_texto']);
        $this->assertSame('atraso', $atraso['tom']);
        $this->assertStringContainsString('Acima do limite de 30 dias', $atraso['regra']);
        $this->assertStringContainsString('prosseguir com dose única', $atraso['regra']);
    }

    public function test_simulador_trata_adulto_sem_historico_pela_serie_propria(): void
    {
        [$versao, $imunobiologico] = $this->cenarioVigente();

        $resposta = $this->actingAs($this->admin())->postJson('/api/plataforma/protocolos/simular', [
            'versao_id' => $versao->id,
            'imunobiologico_id' => $imunobiologico->id,
            'caso' => 'adulto_sem_historico',
        ]);

        $resposta->assertOk();
        $resposta->assertJsonPath('simulacao.adulto_sem_historico', true);

        // Duas doses, e não as três do filhote (RN33 não se aplica a quem já
        // passou da janela dos anticorpos maternos).
        $resposta->assertJsonPath('simulacao.serie_considerada', 2);

        $passos = collect($resposta->json('simulacao.passos'));

        $this->assertStringContainsString('Adulto sem histórico · série de 2 doses', $passos[0]['regra']);
        // O rótulo é o mesmo da carteira do tutor, e não um inventado aqui:
        // com série de duas, a segunda encerra a série primária.
        $this->assertSame('2ª dose · final da série', $passos[1]['rotulo']);
        $this->assertSame('previsto', $passos[1]['tom']);
    }

    public function test_simulador_agenda_dose_adicional_quando_a_final_veio_cedo_demais(): void
    {
        [$versao, $imunobiologico] = $this->cenarioVigente();

        $resposta = $this->actingAs($this->admin())->postJson('/api/plataforma/protocolos/simular', [
            'versao_id' => $versao->id,
            'imunobiologico_id' => $imunobiologico->id,
            'caso' => 'dose_final_antes_da_idade',
        ]);

        $resposta->assertOk();

        $adicional = collect($resposta->json('simulacao.passos'))->firstWhere('rotulo', 'Dose adicional');

        $this->assertNotNull($adicional['data']);
        $this->assertSame('extra', $adicional['tom']);
        $this->assertStringContainsString('anticorpos de origem materna', $adicional['regra']);
        $this->assertStringContainsString('+ 4 semanas', $adicional['valor_texto']);
    }

    public function test_simulador_aceita_entradas_livres(): void
    {
        [$versao, $imunobiologico] = $this->cenarioVigente();

        $resposta = $this->actingAs($this->admin())->postJson('/api/plataforma/protocolos/simular', [
            'versao_id' => $versao->id,
            'imunobiologico_id' => $imunobiologico->id,
            'nascimento_em' => '2025-12-01',
            'doses' => ['2026-02-09', '2026-03-02'],
        ]);

        $resposta->assertOk();
        $resposta->assertJsonPath('simulacao.entradas.doses', ['2026-02-09', '2026-03-02']);

        $passos = collect($resposta->json('simulacao.passos'));

        // 21 dias exatos entre a 1ª e a 2ª: intervalo cumprido, sem atraso.
        $this->assertNull($passos->firstWhere('rotulo', 'Atraso'));
        $this->assertSame('2026-03-23', $passos->firstWhere('rotulo', '3ª dose · final da série')['data']);
    }

    public function test_simulacao_exige_parametros_para_o_imunobiologico(): void
    {
        [$versao] = $this->cenarioVigente();
        $semParametros = Imunobiologico::factory()->create(['chave' => 'leucemia-felina']);

        $this->actingAs($this->admin())
            ->postJson('/api/plataforma/protocolos/simular', [
                'versao_id' => $versao->id,
                'imunobiologico_id' => $semParametros->id,
            ])
            ->assertUnprocessable();
    }
}
