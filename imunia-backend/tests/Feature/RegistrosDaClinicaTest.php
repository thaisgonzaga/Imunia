<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Autorizacao;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrosDaClinicaTest extends TestCase
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
     * Um animal **sem** autorização alguma, de propósito: o âmbito deste livro
     * é a autoria, e a maior parte dos cenários não precisa de autorização
     * para existir. Quem examina o endereço da linha a concede via
     * `autorizar()`.
     */
    private function animal(string $nome = 'Théo'): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => "Tutor de {$nome}"]);

        return Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => $nome]);
    }

    private function autorizar(Animal $animal, Prestador $prestador): Autorizacao
    {
        return Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $animal->tutor->user_id,
        ]);
    }

    private function antirrabica(): Imunobiologico
    {
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        ProtocoloVacinal::factory()->antirrabica()->create(['imunobiologico_id' => $imunobiologico->id]);

        return $imunobiologico;
    }

    private function atender(Animal $animal, Prestador $prestador, string $quando, array $atributos = []): Atendimento
    {
        return Atendimento::factory()->create([
            ...$atributos,
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'atendido_em' => $quando,
        ]);
    }

    private function aplicar(
        Animal $animal,
        Prestador $prestador,
        Imunobiologico $imunobiologico,
        string $quando,
        array $atributos = [],
    ): Vacinacao {
        return Vacinacao::factory()->create([
            ...$atributos,
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()->id,
            'aplicado_em' => $quando,
        ]);
    }

    public function test_a_consulta_exige_sessao(): void
    {
        $this->getJson('/api/clinica/registros')->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_alcanca_o_livro(): void
    {
        $tutor = Tutor::factory()->create();

        $this->actingAs($tutor->user)
            ->getJson('/api/clinica/registros')
            ->assertForbidden();
    }

    /**
     * RN08 — o livro é registro clínico da primeira à última linha. O
     * administrador da conta não o alcança, como não alcança V01 nem a
     * relação de animais.
     */
    public function test_o_administrador_do_prestador_nao_alcanca_o_livro(): void
    {
        $clinica = $this->clinica();
        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($clinica, ['papel' => 'admin_prestador']);

        $this->actingAs($usuario)
            ->getJson('/api/clinica/registros')
            ->assertForbidden();
    }

    /**
     * Livro se folheia do que acabou de acontecer para trás, com os dois tipos
     * de registro na mesma linha do tempo — e cada linha diz o que foi, de
     * quem foi e quem assinou (RN22).
     */
    public function test_o_livro_mistura_os_tipos_em_ordem_cronologica_inversa(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $animal = $this->animal('Pipoca');
        $this->atender($animal, $clinica, now()->subDays(10)->toDateTimeString(), ['titulo' => 'Dermatite']);
        $this->aplicar($animal, $clinica, $antirrabica, now()->subDays(5)->toDateTimeString(), ['ordem_dose' => 2]);
        $this->atender($animal, $clinica, now()->subDay()->toDateTimeString(), ['titulo' => 'Retorno da dermatite']);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/registros');

        $resposta->assertOk();
        $resposta->assertJsonPath('total', 3);
        $resposta->assertJsonPath('itens.0.tipo', 'atendimento');
        $resposta->assertJsonPath('itens.0.titulo', 'Retorno da dermatite');
        $resposta->assertJsonPath('itens.1.tipo', 'vacinacao');
        $resposta->assertJsonPath('itens.1.titulo', 'antirrábica');
        $resposta->assertJsonPath('itens.1.dose', 2);
        $resposta->assertJsonPath('itens.1.responsavel.nome', 'Dr. Marcelo Andrade');
        $resposta->assertJsonPath('itens.1.responsavel.crmv', 'CRMV-MG 12345');
        $resposta->assertJsonPath('itens.2.titulo', 'Dermatite');
        $resposta->assertJsonPath('itens.2.animal.nome', 'Pipoca');
        $resposta->assertJsonPath('itens.2.tutor', 'Tutor de Pipoca');
    }

    /**
     * O âmbito é a autoria, e as duas direções do contraste com a relação de
     * animais precisam valer: a autorização vigente não põe no livro o que o
     * prestador não produziu, e a falta dela não tira o que produziu.
     */
    public function test_o_ambito_e_a_autoria_e_nao_a_autorizacao(): void
    {
        $clinica = $this->clinica();
        $outro = $this->clinica('Hospital Bicho Bom');
        $marcelo = $this->marcelo($clinica);

        // Autorizado, mas nunca atendido aqui: figura na relação de animais e
        // não neste livro.
        $autorizado = $this->animal('Théo');
        $this->autorizar($autorizado, $clinica);
        $this->atender($autorizado, $outro, now()->subDays(2)->toDateTimeString());

        // Jamais autorizado, mas atendido aqui: figura neste livro e não na
        // relação de animais.
        $atendido = $this->animal('Bidu');
        $this->atender($atendido, $clinica, now()->subDays(4)->toDateTimeString());

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/registros');

        $resposta->assertJsonPath('total', 1);
        $resposta->assertJsonPath('itens.0.animal.nome', 'Bidu');
    }

    /**
     * RN25 — o histórico pregresso não passou por prestador nenhum: o que o
     * tutor lançou de memória jamais entra no livro de uma clínica.
     */
    public function test_o_registro_pregresso_nao_entra_no_livro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $animal = $this->animal('Mel');
        Vacinacao::factory()->pregresso()->semImunobiologico()->create([
            'animal_id' => $animal->id,
            'aplicado_em' => now()->subMonth(),
        ]);

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/registros')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('estado', 'sem_registros');
    }

    /**
     * RN40 — a revogação não alcança o que o próprio prestador produziu: a
     * linha fica no livro. O que ela perde é o endereço, porque a leitura
     * integral de V09 monta a série e a situação do animal com registros de
     * todos os prestadores, e isso RN48 fecha com a revogação.
     */
    public function test_sem_autorizacao_vigente_a_linha_fica_e_o_endereco_sai(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $vigente = $this->animal('Théo');
        $this->autorizar($vigente, $clinica);
        $aberto = $this->atender($vigente, $clinica, now()->subDay()->toDateTimeString());

        $revogado = $this->animal('Bidu');
        Autorizacao::factory()->revogada()->create([
            'animal_id' => $revogado->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $revogado->tutor->user_id,
        ]);
        $this->atender($revogado, $clinica, now()->subDays(2)->toDateTimeString());

        $expirado = $this->animal('Mel');
        Autorizacao::factory()->expirada()->create([
            'animal_id' => $expirado->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $expirado->tutor->user_id,
        ]);
        $this->atender($expirado, $clinica, now()->subDays(3)->toDateTimeString());

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/registros');

        $resposta->assertJsonPath('total', 3);

        $resposta->assertJsonPath('itens.0.animal.nome', 'Théo');
        $resposta->assertJsonPath('itens.0.sob_autorizacao', true);
        $resposta->assertJsonPath(
            'itens.0.url',
            "/clinica/animais/{$vigente->codigo}/atendimentos/{$aberto->id}?prestador={$clinica->id}",
        );

        $resposta->assertJsonPath('itens.1.animal.nome', 'Bidu');
        $resposta->assertJsonPath('itens.1.sob_autorizacao', false);
        $resposta->assertJsonPath('itens.1.url', null);

        $resposta->assertJsonPath('itens.2.animal.nome', 'Mel');
        $resposta->assertJsonPath('itens.2.sob_autorizacao', false);
        $resposta->assertJsonPath('itens.2.url', null);
    }

    /**
     * RN26 e RF33b — nada sai do livro por ter sido corrigido. A retificação
     * conserva a data do ato e aparece ao lado do registro que corrige, cada
     * linha com a sua marca: uma diz "esta é uma correção", a outra, "a
     * versão que vale desta é outra".
     */
    public function test_a_retificacao_e_o_original_ficam_lado_a_lado_com_as_marcas(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $animal = $this->animal('Pipoca');
        $this->autorizar($animal, $clinica);

        $original = $this->atender($animal, $clinica, now()->subDays(6)->toDateTimeString());
        $correcao = Atendimento::factory()->retificando($original)->create();
        $this->atender($animal, $clinica, now()->subDay()->toDateTimeString(), ['titulo' => 'Retorno']);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/registros');

        $resposta->assertJsonPath('total', 3);
        $resposta->assertJsonPath('itens.0.titulo', 'Retorno');

        // Mesma data de ato; a correção, gravada depois, vem antes na ordem
        // inversa — e as duas ficam adjacentes.
        $resposta->assertJsonPath('itens.1.id', $correcao->id);
        $resposta->assertJsonPath('itens.1.retifica', true);
        $resposta->assertJsonPath('itens.1.retificado', false);
        $resposta->assertJsonPath('itens.2.id', $original->id);
        $resposta->assertJsonPath('itens.2.retifica', false);
        $resposta->assertJsonPath('itens.2.retificado', true);
    }

    public function test_os_filtros_de_tipo_e_profissional_sao_combinaveis(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $colega = User::factory()->create(['name' => 'Beatriz Franco']);
        $antirrabica = $this->antirrabica();

        $animal = $this->animal('Théo');
        $this->atender($animal, $clinica, now()->subDays(3)->toDateTimeString(), [
            'profissional_user_id' => $marcelo->id,
        ]);
        $this->aplicar($animal, $clinica, $antirrabica, now()->subDays(2)->toDateTimeString(), [
            'aplicador_user_id' => $colega->id,
            'aplicador_nome' => 'Dra. Beatriz Franco',
            'aplicador_crmv' => 'CRMV-MG 67890',
        ]);

        $atuando = $this->actingAs($marcelo);

        $atuando->getJson('/api/clinica/registros')->assertJsonPath('total', 2);

        $atuando->getJson('/api/clinica/registros?tipo=vacinacao')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.tipo', 'vacinacao');

        $atuando->getJson("/api/clinica/registros?profissional={$marcelo->id}")
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.tipo', 'atendimento');

        // A combinação sem resultado conserva o total sem filtros: a tela diz
        // "0 de 2 registros" em vez de fingir que o livro esvaziou.
        $atuando->getJson("/api/clinica/registros?tipo=vacinacao&profissional={$marcelo->id}")
            ->assertJsonPath('total', 0)
            ->assertJsonPath('total_sem_filtros', 2);

        // Valor fora da lista volta ao padrão (RF48b) — inclusive o id de quem
        // não assina registro algum neste livro.
        $atuando->getJson('/api/clinica/registros?tipo=qualquer')
            ->assertJsonPath('filtros.tipo', null)
            ->assertJsonPath('total', 2);

        $atuando->getJson('/api/clinica/registros?profissional=999')
            ->assertJsonPath('filtros.profissional', null)
            ->assertJsonPath('total', 2);
    }

    /**
     * As opções do filtro saem do próprio livro, não da equipe ativa: quem
     * teve o vínculo encerrado continua assinando o que produziu (RF10b), e o
     * filtro precisa continuar a encontrá-lo.
     */
    public function test_as_opcoes_de_profissional_saem_do_proprio_livro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $desligada = User::factory()->create(['name' => 'Beatriz Franco']);

        $animal = $this->animal('Théo');
        $this->atender($animal, $clinica, now()->subDays(2)->toDateTimeString(), [
            'profissional_user_id' => $marcelo->id,
        ]);
        $this->atender($animal, $clinica, now()->subDays(9)->toDateTimeString(), [
            'profissional_user_id' => $desligada->id,
            'profissional_nome' => 'Dra. Beatriz Franco',
            'profissional_crmv' => 'CRMV-MG 67890',
        ]);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/registros');

        $resposta->assertJsonCount(2, 'filtros.opcoes.profissionais');
        $resposta->assertJsonPath('filtros.opcoes.profissionais.0.rotulo', 'Dr. Marcelo Andrade');
        $resposta->assertJsonPath('filtros.opcoes.profissionais.1.rotulo', 'Dra. Beatriz Franco');
        $resposta->assertJsonPath('filtros.opcoes.profissionais.1.valor', $desligada->id);
    }

    public function test_o_livro_nao_mistura_prestadores(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Bicho Bom');
        $marcelo = $this->marcelo($clinica, $hospital);

        $this->atender($this->animal('Théo'), $clinica, now()->subDay()->toDateTimeString());
        $this->atender($this->animal('Amora'), $hospital, now()->subDays(2)->toDateTimeString());

        $this->actingAs($marcelo)->getJson('/api/clinica/registros')
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.animal.nome', 'Théo');

        $this->actingAs($marcelo)->getJson("/api/clinica/registros?prestador={$hospital->id}")
            ->assertJsonPath('prestador.nome', 'Hospital Bicho Bom')
            ->assertJsonPath('itens.0.animal.nome', 'Amora')
            ->assertJsonCount(2, 'vinculos');
    }

    public function test_prestador_sem_vinculo_com_o_profissional_e_recusado(): void
    {
        $clinica = $this->clinica();
        $alheio = $this->clinica('Clínica de Outrem');
        $marcelo = $this->marcelo($clinica);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/registros?prestador={$alheio->id}")
            ->assertForbidden();
    }

    public function test_o_livro_e_paginado_em_vinte_e_cinco_linhas(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animal('Théo');

        foreach (range(1, 26) as $ordem) {
            $this->atender($animal, $clinica, now()->subDays($ordem)->toDateTimeString(), [
                'titulo' => sprintf('Consulta %02d', $ordem),
            ]);
        }

        $atuando = $this->actingAs($marcelo);

        $atuando->getJson('/api/clinica/registros')
            ->assertJsonPath('paginacao.paginas', 2)
            ->assertJsonPath('paginacao.de', 1)
            ->assertJsonPath('paginacao.ate', 25)
            ->assertJsonCount(25, 'itens');

        $atuando->getJson('/api/clinica/registros?pagina=2')
            ->assertJsonPath('paginacao.de', 26)
            ->assertJsonPath('paginacao.ate', 26)
            ->assertJsonPath('itens.0.titulo', 'Consulta 26');
    }
}
