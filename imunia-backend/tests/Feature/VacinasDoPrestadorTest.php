<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\User;
use App\Models\Vacinacao;
use App\Models\VersaoProtocolo;
use App\Services\CalendarioVacinalService;
use App\Services\PublicacaoDeProtocoloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A04 — as vacinas da clínica (RF23, RN30).
 *
 * O que estes testes sustentam são duas promessas opostas e igualmente
 * necessárias. A primeira é de poder: quem administra a conta cadastra a vacina
 * que usa e define quando o tutor deve ser lembrado dela. A segunda é de
 * limite: o catálogo mantido a partir das diretrizes da WSAVA não é editável
 * daqui, porque o cálculo dele responde por todas as clínicas e não por esta
 * (RN31).
 *
 * O teste central é `test_data_ja_calculada_nao_muda_depois_da_edicao_do_agendamento`,
 * irmão de `test_publicar_nao_recalcula_data_ja_emitida` em X02: é a mesma
 * promessa de RN32, feita agora sobre um agendamento que a própria clínica
 * escreveu.
 */
class VacinasDoPrestadorTest extends TestCase
{
    use RefreshDatabase;

    private function clinica(): Prestador
    {
        return Prestador::factory()->create();
    }

    private function administradora(Prestador $prestador): User
    {
        $usuario = User::factory()->create(['name' => 'Ana Lúcia Ferraz']);
        $usuario->prestadores()->attach($prestador, ['papel' => 'admin_prestador']);

        return $usuario;
    }

    private function veterinario(Prestador $prestador): User
    {
        $usuario = User::factory()->create(['name' => 'Marcelo Andrade']);
        $usuario->prestadores()->attach($prestador, [
            'papel' => 'veterinario',
            'crmv' => '12345',
            'crmv_uf' => 'MG',
        ]);

        return $usuario;
    }

    /** Um item do catálogo da plataforma — o que a clínica vê e não toca. */
    private function oficial(): Imunobiologico
    {
        return Imunobiologico::factory()->create(['chave' => 'triplice-felina']);
    }

    /**
     * O formulário íntegro, para o teste mudar só o que lhe interessa.
     *
     * @param  array<string, mixed>  $mudancas
     * @return array<string, mixed>
     */
    private function formulario(array $mudancas = []): array
    {
        return [
            'nome_comercial' => 'Vacina da casa',
            'fabricante' => 'Laboratório Local',
            'agentes_cobertos' => 'giardíase',
            'especie_destino' => 'cao',
            'via_administracao_usual' => 'Subcutânea',
            'doses_primeira_vez' => 2,
            'intervalo_semanas' => 3,
            'repete' => true,
            'periodicidade_valor' => 1,
            'periodicidade_unidade' => 'anos',
            'primeiro_reforco_diferente' => false,
            ...$mudancas,
        ];
    }

    public function test_visitante_nao_autenticado_nao_alcanca_as_vacinas_da_clinica(): void
    {
        $this->getJson('/api/prestador/vacinas')->assertUnauthorized();
    }

    public function test_veterinario_sem_administracao_ve_mas_nao_cadastra(): void
    {
        $clinica = $this->clinica();
        $this->oficial();

        // Ver que vacinas a casa usa não é administrar: a leitura é de quem tem
        // vínculo, como em A02 e A03.
        $this->actingAs($this->veterinario($clinica))
            ->getJson('/api/prestador/vacinas')
            ->assertOk()
            ->assertJsonPath('pode_administrar', false)
            ->assertJsonCount(1, 'oficiais');

        $this->actingAs($this->veterinario($clinica))
            ->postJson('/api/prestador/vacinas', $this->formulario())
            ->assertForbidden();
    }

    public function test_catalogo_da_plataforma_vem_marcado_como_bloqueado(): void
    {
        $clinica = $this->clinica();
        $oficial = $this->oficial();
        ProtocoloVacinal::factory()->create(['imunobiologico_id' => $oficial->id]);

        // O cadeado da tela sai do servidor, e não de um palpite da interface
        // sobre a origem do item.
        $this->actingAs($this->administradora($clinica))
            ->getJson('/api/prestador/vacinas')
            ->assertOk()
            ->assertJsonPath('oficiais.0.bloqueado', true)
            ->assertJsonPath('oficiais.0.agendamento_respostas', null)
            ->assertJsonCount(0, 'proprias');
    }

    public function test_admin_prestador_nao_edita_item_do_catalogo_da_plataforma(): void
    {
        $clinica = $this->clinica();
        $oficial = $this->oficial();

        $this->actingAs($this->administradora($clinica))
            ->postJson("/api/prestador/vacinas/{$oficial->id}", $this->formulario())
            ->assertForbidden()
            // A recusa nomeia o motivo: o item está na lista que a tela acabou
            // de mostrar, e esconder a razão mandaria procurar o que já se achou.
            ->assertJsonPath('message', fn (string $mensagem) => str_contains($mensagem, 'catálogo da plataforma'));
    }

    public function test_admin_prestador_nao_inativa_item_do_catalogo_da_plataforma(): void
    {
        $clinica = $this->clinica();
        $oficial = $this->oficial();

        // A mesma guarda na rota que não passa por FormRequest alguma.
        $this->actingAs($this->administradora($clinica))
            ->postJson("/api/prestador/vacinas/{$oficial->id}/inativar")
            ->assertForbidden();

        $this->assertTrue($oficial->fresh()->ativo);
    }

    public function test_vacina_de_outra_clinica_responde_como_inexistente(): void
    {
        $outra = $this->clinica();
        $alheia = Imunobiologico::factory()->create(['chave' => 'p99-vacina-alheia']);
        $alheia->forceFill(['prestador_id' => $outra->id])->save();

        // 404 e não 403: para esta conta o item não existe, e dizer "existe mas
        // não é seu" contaria que alguma outra clínica cadastrou alguma coisa.
        $this->actingAs($this->administradora($this->clinica()))
            ->postJson("/api/prestador/vacinas/{$alheia->id}", $this->formulario())
            ->assertNotFound();
    }

    public function test_vacina_propria_nasce_com_o_prestador_e_com_chave_prefixada(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas', $this->formulario())
            ->assertCreated()
            ->assertJsonCount(1, 'proprias')
            ->assertJsonPath('proprias.0.bloqueado', false)
            ->assertJsonPath('proprias.0.nome_comercial', 'Vacina da casa');

        $criada = Imunobiologico::where('nome_comercial', 'Vacina da casa')->firstOrFail();

        $this->assertSame($clinica->id, $criada->prestador_id);
        $this->assertSame("p{$clinica->id}-vacina-da-casa", $criada->chave);

        // A clínica não classifica em essencial ou não essencial: isso é
        // leitura de diretriz (RN31).
        $this->assertSame('nao_essencial', $criada->classificacao);
    }

    public function test_agendamento_proprio_nao_pertence_a_versao_publicada(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas', $this->formulario())
            ->assertCreated();

        $protocolo = Imunobiologico::where('nome_comercial', 'Vacina da casa')
            ->firstOrFail()
            ->protocoloVigente();

        // A nulidade é a garantia estrutural: sem versão, o rascunho da
        // plataforma não copia o agendamento, a conferência de incoerências não
        // o examina e a publicação seguinte não o tira de vigência.
        $this->assertNull($protocolo->versao_protocolo_id);
        $this->assertSame(2, $protocolo->numero_doses_serie_primaria);
        $this->assertSame(21, $protocolo->intervalo_minimo_dias);
        $this->assertSame(21, $protocolo->intervalo_maximo_dias);
        $this->assertSame(12, $protocolo->periodicidade_revacinacao_meses);
        $this->assertNull($protocolo->reforco_inicial_meses);

        // As idades mínimas ficam nulas de propósito: são leitura de diretriz
        // sobre anticorpos maternos (RN33), e a da dose final é a que faria o
        // motor agendar uma dose adicional que a WSAVA nunca pediu.
        $this->assertNull($protocolo->idade_minima_dose_final_semanas);
        $this->assertNull($protocolo->idade_minima_primeira_dose_semanas);
    }

    public function test_dose_unica_sem_revacinacao_grava_periodicidade_nula(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas', $this->formulario([
                'doses_primeira_vez' => 1,
                'intervalo_semanas' => null,
                'repete' => false,
                'periodicidade_valor' => null,
                'periodicidade_unidade' => null,
            ]))
            ->assertCreated()
            ->assertJsonPath('proprias.0.agendamento', 'Dose única, sem revacinação');

        $protocolo = Imunobiologico::where('nome_comercial', 'Vacina da casa')->firstOrFail()->protocoloVigente();

        $this->assertNull($protocolo->periodicidade_revacinacao_meses);
        $this->assertSame(1, $protocolo->numero_doses_serie_primaria);
        $this->assertSame(0, $protocolo->intervalo_minimo_dias);
    }

    public function test_reforco_em_anos_e_convertido_para_meses(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas', $this->formulario([
                'periodicidade_valor' => 3,
                'periodicidade_unidade' => 'anos',
                'primeiro_reforco_diferente' => true,
                'primeiro_reforco_valor' => 6,
                'primeiro_reforco_unidade' => 'meses',
            ]))
            ->assertCreated()
            ->assertJsonPath(
                'proprias.0.agendamento',
                '2 doses a cada 21 dias · primeiro reforço em 6 meses · depois, a cada 3 anos',
            );

        $protocolo = Imunobiologico::where('nome_comercial', 'Vacina da casa')->firstOrFail()->protocoloVigente();

        $this->assertSame(36, $protocolo->periodicidade_revacinacao_meses);
        $this->assertSame(6, $protocolo->reforco_inicial_meses);
    }

    public function test_primeiro_reforco_mais_distante_que_a_periodicidade_e_recusado(): void
    {
        $clinica = $this->clinica();

        // A revacinação seguinte cairia antes do primeiro reforço — a única
        // incoerência que este formulário consegue produzir.
        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas', $this->formulario([
                'periodicidade_valor' => 6,
                'periodicidade_unidade' => 'meses',
                'primeiro_reforco_diferente' => true,
                'primeiro_reforco_valor' => 2,
                'primeiro_reforco_unidade' => 'anos',
            ]))
            ->assertJsonValidationErrors('primeiro_reforco_valor');
    }

    public function test_editar_agendamento_cria_linha_nova_e_nao_altera_a_anterior(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->actingAs($administradora)
            ->postJson('/api/prestador/vacinas', $this->formulario())
            ->assertCreated();

        $imunobiologico = Imunobiologico::where('nome_comercial', 'Vacina da casa')->firstOrFail();
        $original = $imunobiologico->protocoloVigente();

        $this->actingAs($administradora)
            ->postJson("/api/prestador/vacinas/{$imunobiologico->id}", $this->formulario([
                'periodicidade_valor' => 3,
                'periodicidade_unidade' => 'anos',
            ]))
            ->assertOk();

        // Nenhuma linha de parâmetros é alterada depois de criada: editar é
        // criar a seguinte, como publicar uma versão é criar as linhas dela.
        $this->assertSame(2, $imunobiologico->protocolos()->count());
        $this->assertSame(12, $original->fresh()->periodicidade_revacinacao_meses);
        $this->assertSame(36, $imunobiologico->fresh()->protocoloVigente()->periodicidade_revacinacao_meses);
    }

    public function test_data_ja_calculada_nao_muda_depois_da_edicao_do_agendamento(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->actingAs($administradora)
            ->postJson('/api/prestador/vacinas', $this->formulario(['doses_primeira_vez' => 1, 'intervalo_semanas' => null]))
            ->assertCreated();

        $imunobiologico = Imunobiologico::where('nome_comercial', 'Vacina da casa')->firstOrFail();

        $animal = Animal::factory()->create();
        Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()->id,
            'aplicado_em' => now()->subMonth(),
            'data_aproximada' => false,
            'ordem_dose' => 1,
        ]);

        $calendario = app(CalendarioVacinalService::class);
        $antes = $calendario->montarCarteira($animal)['grupos'][0]['proxima_dose']['prevista_para'];

        $this->actingAs($administradora)
            ->postJson("/api/prestador/vacinas/{$imunobiologico->id}", $this->formulario([
                'doses_primeira_vez' => 1,
                'intervalo_semanas' => null,
                'periodicidade_valor' => 5,
                'periodicidade_unidade' => 'anos',
            ]))
            ->assertOk();

        $depois = $calendario->montarCarteira($animal->fresh())['grupos'][0]['proxima_dose']['prevista_para'];

        // RN32 — a aplicação congelou a linha que explicou o cálculo dela, e a
        // linha não mudou. O prazo novo vale para as aplicações a partir de
        // agora, e não para a data que o tutor já viu.
        $this->assertSame($antes, $depois);
    }

    public function test_previa_do_agendamento_nao_grava_nada(): void
    {
        $clinica = $this->clinica();

        $imunobiologicos = Imunobiologico::query()->count();
        $protocolos = ProtocoloVacinal::query()->count();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas/previa', [
                'doses_primeira_vez' => 3,
                'intervalo_semanas' => 4,
                'repete' => true,
                'periodicidade_valor' => 1,
                'periodicidade_unidade' => 'anos',
                'primeiro_reforco_diferente' => false,
            ])
            ->assertOk()
            ->assertJsonPath('resumo', '3 doses a cada 28 dias · reforço anual')
            ->assertJsonCount(4, 'passos');

        $this->assertSame($imunobiologicos, Imunobiologico::query()->count());
        $this->assertSame($protocolos, ProtocoloVacinal::query()->count());
    }

    public function test_previa_anuncia_que_nao_havera_revacinacao(): void
    {
        $clinica = $this->clinica();

        $resposta = $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas/previa', [
                'doses_primeira_vez' => 1,
                'repete' => false,
                'primeiro_reforco_diferente' => false,
            ])
            ->assertOk();

        $this->assertSame('Série concluída', $resposta->json('passos.1.rotulo'));
        $this->assertNull($resposta->json('passos.1.data'));
    }

    public function test_inativar_impede_nova_aplicacao_e_preserva_o_item(): void
    {
        $clinica = $this->clinica();
        $administradora = $this->administradora($clinica);

        $this->actingAs($administradora)
            ->postJson('/api/prestador/vacinas', $this->formulario())
            ->assertCreated();

        $imunobiologico = Imunobiologico::where('nome_comercial', 'Vacina da casa')->firstOrFail();

        $this->actingAs($administradora)
            ->postJson("/api/prestador/vacinas/{$imunobiologico->id}/inativar")
            ->assertOk()
            // Inativação, nunca exclusão (RF23b): o item continua na lista da
            // clínica, com a ação de reativar.
            ->assertJsonPath('proprias.0.ativo', false);

        $this->assertDatabaseHas('imunobiologicos', ['id' => $imunobiologico->id, 'ativo' => false]);
    }

    public function test_publicar_versao_nova_nao_tira_de_vigencia_o_agendamento_da_clinica(): void
    {
        $clinica = $this->clinica();

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas', $this->formulario())
            ->assertCreated();

        $imunobiologico = Imunobiologico::where('nome_comercial', 'Vacina da casa')->firstOrFail();
        $antes = $imunobiologico->protocoloVigente();

        // A plataforma publica a revisão seguinte da WSAVA. Fosse a linha
        // privada filha de uma versão, ela seria encerrada junto — e o lembrete
        // de toda vacina própria de toda clínica cessaria em silêncio.
        VersaoProtocolo::query()->update(['situacao' => VersaoProtocolo::ENCERRADA]);
        VersaoProtocolo::factory()->create(['rotulo' => '2027.1']);

        $this->assertNotNull($imunobiologico->fresh()->protocoloVigente());
        $this->assertSame($antes->id, $imunobiologico->fresh()->protocoloVigente()->id);
    }

    public function test_novo_rascunho_da_plataforma_nao_copia_agendamento_de_clinica(): void
    {
        $clinica = $this->clinica();
        $vigente = VersaoProtocolo::factory()->create(['rotulo' => '2024.1']);
        $oficial = $this->oficial();
        ProtocoloVacinal::factory()->create([
            'imunobiologico_id' => $oficial->id,
            'versao_protocolo_id' => $vigente->id,
        ]);

        $this->actingAs($this->administradora($clinica))
            ->postJson('/api/prestador/vacinas', $this->formulario())
            ->assertCreated();

        $rascunho = app(PublicacaoDeProtocoloService::class)
            ->criarRascunhoAPartirDaVigente('2026.1', 'WSAVA 2024');

        // A cópia percorre `$vigente->protocolos`, e a linha sem versão não está
        // lá. O administrador da plataforma não herda os parâmetros de clínica
        // alguma.
        $this->assertSame(1, $rascunho->protocolos()->count());
        $this->assertSame($oficial->id, $rascunho->protocolos()->first()->imunobiologico_id);
    }
}
