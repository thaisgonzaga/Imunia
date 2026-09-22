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

class AnimaisDaClinicaTest extends TestCase
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

    private function animalAutorizado(Prestador $prestador, string $nome = 'Théo', array $atributos = []): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => "Tutor de {$nome}"]);
        $animal = Animal::factory()->create([...$atributos, 'tutor_id' => $tutor->id, 'nome' => $nome]);

        Autorizacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        return $animal;
    }

    private function antirrabica(): Imunobiologico
    {
        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        ProtocoloVacinal::factory()->antirrabica()->create(['imunobiologico_id' => $imunobiologico->id]);

        return $imunobiologico;
    }

    /**
     * Uma aplicação isolada de antirrábica. Como o reforço é anual, a data da
     * aplicação decide sozinha a situação da carteira: catorze meses atrás
     * produz carteira atrasada; vinte dias atrás, carteira em dia.
     */
    private function aplicar(Animal $animal, Prestador $prestador, Imunobiologico $imunobiologico, string $quando): Vacinacao
    {
        return Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()->id,
            'aplicado_em' => $quando,
        ]);
    }

    public function test_a_consulta_exige_sessao(): void
    {
        $this->getJson('/api/clinica/animais')->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_alcanca_a_relacao(): void
    {
        $tutor = Tutor::factory()->create();

        $this->actingAs($tutor->user)
            ->getJson('/api/clinica/animais')
            ->assertForbidden();
    }

    /**
     * RN08 — a relação de animais é da equipe clínica. O administrador da
     * conta não tem esta tela, como não tem V01 nem V02.
     */
    public function test_o_administrador_do_prestador_nao_alcanca_a_relacao(): void
    {
        $clinica = $this->clinica();
        $usuario = User::factory()->create();
        $usuario->prestadores()->attach($clinica, ['papel' => 'admin_prestador']);

        $this->actingAs($usuario)
            ->getJson('/api/clinica/animais')
            ->assertForbidden();
    }

    /**
     * RN48 — o que traz o animal para a relação é a autorização vigente do
     * tutor, e nada mais: nem o atendimento passado, nem a vacina aplicada
     * aqui mesmo.
     */
    public function test_animal_sem_autorizacao_vigente_nao_figura_na_relacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $this->animalAutorizado($clinica, 'Théo');

        $semAutorizacao = Animal::factory()->create(['nome' => 'Bidu']);
        $this->aplicar($semAutorizacao, $clinica, $antirrabica, now()->subMonths(2));

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/animais');

        $resposta->assertOk();
        $resposta->assertJsonPath('total', 1);
        $resposta->assertJsonPath('itens.0.nome', 'Théo');
    }

    public function test_autorizacao_revogada_ou_expirada_deixa_a_relacao_vazia(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        foreach (['revogada', 'expirada'] as $estado) {
            $animal = Animal::factory()->create(['nome' => "Animal {$estado}"]);
            Autorizacao::factory()->{$estado}()->create([
                'animal_id' => $animal->id,
                'prestador_id' => $clinica->id,
                'concedida_por_user_id' => $animal->tutor->user_id,
            ]);
        }

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/animais')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('estado', 'sem_autorizacoes');
    }

    /**
     * Lista de navegação se percorre como catálogo: a ordem é alfabética, e a
     * urgência fica com V02, que já ordena por atraso.
     */
    public function test_a_relacao_vem_ordenada_por_nome(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        foreach (['Pipoca', 'Amora', 'Théo'] as $nome) {
            $this->animalAutorizado($clinica, $nome);
        }

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/animais');

        $resposta->assertJsonPath('total', 3);
        $resposta->assertJsonPath('itens.0.nome', 'Amora');
        $resposta->assertJsonPath('itens.1.nome', 'Pipoca');
        $resposta->assertJsonPath('itens.2.nome', 'Théo');
    }

    /**
     * A linha traz o que decide a leitura da lista: quem é o animal, de quem é,
     * a situação da carteira e até quando a autorização vale (RN39).
     */
    public function test_a_linha_traz_situacao_da_carteira_e_vencimento_da_autorizacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $atrasado = $this->animalAutorizado($clinica, 'Pipoca');
        $this->aplicar($atrasado, $clinica, $antirrabica, now()->subMonths(14));

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/animais');

        $resposta->assertJsonPath('itens.0.nome', 'Pipoca');
        $resposta->assertJsonPath('itens.0.tutor', 'Tutor de Pipoca');
        $resposta->assertJsonPath('itens.0.preliminar', true); // RN17
        $resposta->assertJsonPath('itens.0.situacao.tipo', 'atrasada');
        $resposta->assertJsonPath('itens.0.autorizacao.a_expirar', false);
        $resposta->assertJsonPath(
            'itens.0.autorizacao.expira_em',
            now()->addDays(Autorizacao::PRAZO_DIAS)->toDateString(),
        );
    }

    /**
     * RN39 — a antecedência de aviso é a mesma da ficha e de T12: a coluna
     * fica âmbar quando faltam quinze dias ou menos.
     */
    public function test_autorizacao_dentro_da_antecedencia_vem_marcada_como_a_expirar(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $tutor = Tutor::factory()->create();
        $animal = Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => 'Mel']);
        Autorizacao::factory()->aExpirar(10)->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $tutor->user_id,
        ]);

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/animais');

        $resposta->assertJsonPath('itens.0.autorizacao.a_expirar', true);
        $resposta->assertJsonPath('itens.0.autorizacao.dias_restantes', 10);
    }

    /**
     * RF50 — carteira sem vacinação registrada não é "em dia" por omissão: a
     * situação vem nula, e o filtro próprio a encontra.
     */
    public function test_animal_sem_vacinacao_registrada_vem_sem_situacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $this->animalAutorizado($clinica, 'Théo');

        $emDia = $this->animalAutorizado($clinica, 'Amora');
        $this->aplicar($emDia, $clinica, $antirrabica, now()->subDays(20));

        $atuando = $this->actingAs($marcelo);

        $atuando->getJson('/api/clinica/animais')
            ->assertJsonPath('itens.1.nome', 'Théo')
            ->assertJsonPath('itens.1.situacao', null);

        $atuando->getJson('/api/clinica/animais?situacao=sem-registro')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.nome', 'Théo');

        $atuando->getJson('/api/clinica/animais?situacao=em-dia')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.nome', 'Amora');
    }

    /**
     * RF22a — registrado o óbito, cessa o cálculo do calendário. A linha fica,
     * com a marca; a etiqueta de situação, não — e o filtro "sem registro"
     * não a oferece como carteira a preencher.
     */
    public function test_animal_com_obito_figura_sem_situacao_vacinal(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $falecido = $this->animalAutorizado($clinica, 'Bidu');
        $this->aplicar($falecido, $clinica, $antirrabica, now()->subMonths(14));
        $falecido->forceFill(['obito_em' => now()->subDays(3)->toDateString()])->save();

        $atuando = $this->actingAs($marcelo);

        $atuando->getJson('/api/clinica/animais')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.obito', true)
            ->assertJsonPath('itens.0.situacao', null);

        $atuando->getJson('/api/clinica/animais?situacao=sem-registro')
            ->assertJsonPath('total', 0);
    }

    /**
     * A última passagem é o registro mais recente **neste** prestador, venha do
     * atendimento ou da vacina. O que aconteceu em outro prestador e o que o
     * tutor lançou de memória (RF29) não contam como passagem pelo balcão.
     */
    public function test_a_ultima_passagem_vem_do_registro_mais_recente_no_prestador(): void
    {
        $clinica = $this->clinica();
        $outro = $this->clinica('Hospital Bicho Bom');
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $animal = $this->animalAutorizado($clinica, 'Pipoca');

        $this->aplicar($animal, $clinica, $antirrabica, now()->subMonths(3));
        Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'atendido_em' => now()->subDays(10),
        ]);

        // Mais recentes, e mesmo assim fora da conta: um é de outro prestador,
        // o outro não passou por prestador nenhum.
        Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $outro->id,
            'atendido_em' => now()->subDays(2),
        ]);
        Vacinacao::factory()->pregresso()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => null,
            'aplicado_em' => now()->subDay(),
        ]);

        $nuncaVeio = $this->animalAutorizado($clinica, 'Amora');

        $resposta = $this->actingAs($marcelo)->getJson('/api/clinica/animais');

        $resposta->assertJsonPath('itens.0.nome', 'Amora');
        $resposta->assertJsonPath('itens.0.ultima_passagem', null);
        $resposta->assertJsonPath('itens.1.nome', 'Pipoca');
        $resposta->assertJsonPath('itens.1.ultima_passagem', now()->subDays(10)->toDateString());
    }

    public function test_os_filtros_de_especie_e_situacao_sao_combinaveis(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $antirrabica = $this->antirrabica();

        $cao = $this->animalAutorizado($clinica, 'Pipoca', ['especie' => 'cao']);
        $this->aplicar($cao, $clinica, $antirrabica, now()->subMonths(14));

        $gata = $this->animalAutorizado($clinica, 'Mel', ['especie' => 'gato']);
        $this->aplicar($gata, $clinica, $antirrabica, now()->subDays(20));

        $atuando = $this->actingAs($marcelo);

        $atuando->getJson('/api/clinica/animais')->assertJsonPath('total', 2);

        $atuando->getJson('/api/clinica/animais?especie=gato')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.nome', 'Mel');

        $atuando->getJson('/api/clinica/animais?situacao=atrasada')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.nome', 'Pipoca');

        // A combinação sem resultado conserva o total sem filtros: a tela diz
        // "0 de 2 animais" em vez de fingir que o plantel sumiu.
        $atuando->getJson('/api/clinica/animais?especie=gato&situacao=atrasada')
            ->assertJsonPath('total', 0)
            ->assertJsonPath('total_sem_filtros', 2);

        // Valor fora da lista volta ao padrão, como em V02 (RF48b).
        $atuando->getJson('/api/clinica/animais?situacao=qualquer')
            ->assertJsonPath('filtros.situacao', null)
            ->assertJsonPath('total', 2);
    }

    public function test_a_relacao_nao_mistura_prestadores(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Bicho Bom');
        $marcelo = $this->marcelo($clinica, $hospital);

        $this->animalAutorizado($clinica, 'Théo');
        $this->animalAutorizado($hospital, 'Amora');

        $this->actingAs($marcelo)->getJson('/api/clinica/animais')
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('itens.0.nome', 'Théo');

        $this->actingAs($marcelo)->getJson("/api/clinica/animais?prestador={$hospital->id}")
            ->assertJsonPath('prestador.nome', 'Hospital Bicho Bom')
            ->assertJsonPath('itens.0.nome', 'Amora')
            ->assertJsonCount(2, 'vinculos');
    }

    public function test_prestador_sem_vinculo_com_o_profissional_e_recusado(): void
    {
        $clinica = $this->clinica();
        $alheio = $this->clinica('Clínica de Outrem');
        $marcelo = $this->marcelo($clinica);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais?prestador={$alheio->id}")
            ->assertForbidden();
    }

    public function test_a_relacao_e_paginada_em_vinte_e_cinco_linhas(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        foreach (range(1, 26) as $ordem) {
            $this->animalAutorizado($clinica, sprintf('Animal %02d', $ordem));
        }

        $atuando = $this->actingAs($marcelo);

        $atuando->getJson('/api/clinica/animais')
            ->assertJsonPath('paginacao.paginas', 2)
            ->assertJsonPath('paginacao.de', 1)
            ->assertJsonPath('paginacao.ate', 25)
            ->assertJsonCount(25, 'itens');

        $atuando->getJson('/api/clinica/animais?pagina=2')
            ->assertJsonPath('paginacao.de', 26)
            ->assertJsonPath('paginacao.ate', 26)
            ->assertJsonPath('itens.0.nome', 'Animal 26');
    }
}
