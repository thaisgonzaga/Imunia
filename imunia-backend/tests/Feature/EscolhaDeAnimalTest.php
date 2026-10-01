<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V07a e V08a — escolher o animal antes de registrar.
 *
 * O atalho é da carteira do prestador: animal que a clínica não acompanha não
 * entra nele. O resto — a busca e o registro de acesso — é o mesmo serviço de
 * V03, e o teste verifica que esta rota não escapou dele.
 */
class EscolhaDeAnimalTest extends TestCase
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

    private function animalDe(string $nomeDoTutor, string $nome, array $atributos = []): Animal
    {
        $tutor = Tutor::factory()->create(['nome' => $nomeDoTutor]);

        return Animal::factory()->create([...$atributos, 'tutor_id' => $tutor->id, 'nome' => $nome]);
    }

    private function animalAcompanhado(Prestador $prestador, string $nome, string $tutor): Animal
    {
        $animal = $this->animalDe($tutor, $nome);
        $prestador->vincular($animal);

        return $animal;
    }

    private function atender(Animal $animal, Prestador $prestador, User $profissional, string $em): Atendimento
    {
        return Atendimento::factory()->create([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'profissional_user_id' => $profissional->id,
            'atendido_em' => $em,
        ]);
    }

    private function escolher(User $usuario, array $consulta = [])
    {
        return $this->actingAs($usuario)
            ->getJson('/api/clinica/registrar?'.http_build_query($consulta));
    }

    /* Porta de entrada ----------------------------------------------------- */

    public function test_a_escolha_exige_sessao(): void
    {
        $this->getJson('/api/clinica/registrar')->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_alcanca_a_escolha(): void
    {
        $tutor = Tutor::factory()->create();

        $this->escolher($tutor->user)->assertForbidden();
    }

    public function test_prestador_sem_vinculo_com_o_profissional_e_recusado(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');

        $this->escolher($this->marcelo($clinica), ['prestador' => $hospital->id])
            ->assertForbidden();
    }

    /* O atalho ------------------------------------------------------------- */

    /**
     * O estado de abertura da tela: nada foi consultado ainda, e o que se
     * oferece é o trabalho do dia.
     */
    public function test_a_tela_abre_com_o_contexto_clinico_e_os_ultimos_atendidos(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalAcompanhado($clinica, 'Théo', 'Helena Ramos');

        $this->atender($theo, $clinica, $marcelo, now()->subDays(2)->toDateTimeString());

        $this->escolher($marcelo)
            ->assertOk()
            ->assertJsonPath('estado', 'inicial')
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo')
            ->assertJsonCount(0, 'autorizados')
            ->assertJsonPath('existencia', null)
            ->assertJsonCount(1, 'recentes')
            ->assertJsonPath('recentes.0.nome', 'Théo')
            ->assertJsonPath('recentes.0.tutor', 'Helena Ramos')
            ->assertJsonPath('recentes.0.codigo', $theo->codigo);
    }

    /**
     * O atalho lê a carteira do prestador, como o painel e as listas: um
     * atendimento que não deixou o animal vinculado não o põe ali.
     */
    public function test_animal_sem_vinculo_fica_fora_do_atalho(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->atender($theo, $clinica, $marcelo, now()->subDays(2)->toDateTimeString());

        $this->escolher($marcelo)->assertOk()->assertJsonCount(0, 'recentes');
    }

    public function test_o_atalho_e_do_prestador_ativo(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($clinica, $hospital);

        $theo = $this->animalAcompanhado($clinica, 'Théo', 'Helena Ramos');
        $hospital->vincular($theo);
        $this->atender($theo, $clinica, $marcelo, now()->subDays(2)->toDateTimeString());

        // Atendido na clínica, e é lá que ele aparece: o hospital também
        // acompanha o mesmo animal, mas ninguém passou por lá.
        $this->escolher($marcelo, ['prestador' => $clinica->id])
            ->assertJsonCount(1, 'recentes');

        $this->escolher($marcelo, ['prestador' => $hospital->id])
            ->assertJsonCount(0, 'recentes');
    }

    public function test_o_atalho_ordena_do_atendimento_mais_recente_para_o_mais_antigo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $theo = $this->animalAcompanhado($clinica, 'Théo', 'Helena Ramos');
        $mel = $this->animalAcompanhado($clinica, 'Mel', 'Antônio Prado');

        $this->atender($theo, $clinica, $marcelo, now()->subDays(9)->toDateTimeString());
        $this->atender($mel, $clinica, $marcelo, now()->subDay()->toDateTimeString());

        $this->escolher($marcelo)
            ->assertJsonPath('recentes.0.nome', 'Mel')
            ->assertJsonPath('recentes.1.nome', 'Théo');
    }

    /**
     * RF29 — o lançamento pregresso é memória do tutor, não passagem pelo
     * balcão. Um animal cujo único vestígio no prestador é um registro que o
     * tutor digitou não foi atendido por ninguém ali.
     */
    public function test_vacinacao_pregressa_nao_poe_o_animal_no_atalho(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $animal = $this->animalAcompanhado($clinica, 'Bidu', 'Ruan Teixeira');

        Vacinacao::factory()->pregresso()->create([
            'animal_id' => $animal->id,
            'aplicado_em' => now()->subDays(3),
        ]);

        $this->escolher($marcelo)->assertJsonCount(0, 'recentes');
    }

    /**
     * A pergunta que a tela responde é "o que este animal está devendo?", e
     * "atrasada" sozinha não a responde: quem vai vacinar precisa saber qual
     * vacina é.
     */
    public function test_o_atalho_nomeia_a_vacina_pendente(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalAcompanhado($clinica, 'Théo', 'Helena Ramos');

        $imunobiologico = Imunobiologico::factory()->antirrabica()->create();
        $protocolo = ProtocoloVacinal::factory()->antirrabica()->create([
            'imunobiologico_id' => $imunobiologico->id,
        ]);

        Vacinacao::factory()->create([
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo->id,
            'aplicado_em' => now()->subMonths(14),
        ]);

        $this->atender($theo, $clinica, $marcelo, now()->subDays(2)->toDateTimeString());

        $this->escolher($marcelo)
            ->assertJsonPath('recentes.0.pendencia.imunobiologico', 'antirrábica')
            ->assertJsonPath('recentes.0.pendencia.situacao.tipo', 'atrasada');
    }

    /**
     * RF50 — sem vacinação registrada, o sistema diz que ainda não sabe. Nem
     * pendência inventada, nem "em dia" por omissão.
     */
    public function test_animal_sem_vacinacao_nao_recebe_pendencia_nem_situacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalAcompanhado($clinica, 'Théo', 'Helena Ramos');

        $this->atender($theo, $clinica, $marcelo, now()->subDays(2)->toDateTimeString());

        $this->escolher($marcelo)
            ->assertJsonPath('recentes.0.pendencia', null)
            ->assertJsonPath('recentes.0.situacao', null);
    }

    /* A busca -------------------------------------------------------------- */

    public function test_a_busca_devolve_o_animal_vinculado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalAcompanhado($clinica, 'Théo', 'Helena Ramos');

        $this->escolher($marcelo, ['termo' => 'Théo'])
            ->assertOk()
            ->assertJsonPath('estado', 'normal')
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.codigo', $theo->codigo)
            ->assertJsonPath('autorizados.0.tutor', 'Helena Ramos')
            ->assertJsonPath('autorizados.0.vinculado', true);
    }

    /**
     * O código alcança qualquer cadastro, como em V03: quem o tem na mão está
     * com o animal à sua frente, e o registro pode começar por ele.
     */
    public function test_codigo_de_animal_sem_vinculo_traz_o_cartao_completo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $pipoca = $this->animalDe('Denise Colombo', 'Pipoca');

        $this->escolher($marcelo, ['termo' => $pipoca->codigo])
            ->assertOk()
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.nome', 'Pipoca')
            ->assertJsonPath('autorizados.0.tutor', 'Denise Colombo')
            ->assertJsonPath('autorizados.0.vinculado', false)
            ->assertJsonPath('existencia', null);
    }

    /**
     * Por nome, a escolha só alcança a carteira — a mesma regra de V03.
     */
    public function test_busca_por_nome_nao_alcanca_animal_de_fora_da_carteira(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->animalDe('Denise Colombo', 'Pipoca');

        $this->escolher($marcelo, ['termo' => 'Pipoca'])
            ->assertOk()
            ->assertJsonPath('estado', 'sem_resultado')
            ->assertJsonCount(0, 'autorizados')
            ->assertJsonMissing(['tutor' => 'Denise Colombo']);

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /**
     * RF18b — o encontro por código de animal que a clínica ainda não
     * acompanha fica registrado, e o tutor o vê (T14).
     * Vale por esta rota como pela busca: o que a decide é a consulta, não a
     * tela de onde ela partiu.
     */
    public function test_a_consulta_por_codigo_de_animal_sem_vinculo_fica_registrada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $pipoca = $this->animalDe('Denise Colombo', 'Pipoca');

        $this->escolher($marcelo, ['termo' => $pipoca->codigo])->assertOk();

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $pipoca->tutor_id,
            'animal_id' => $pipoca->id,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CODIGO,
        ]);
    }

    /**
     * RF13 — o dígito verificador é conferido antes de qualquer consulta, e a
     * promessa de que nada foi consultado nem registrado vale aqui como em V03.
     */
    public function test_cpf_invalido_e_erro_de_campo_e_nao_consulta(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);

        $this->escolher($marcelo, ['termo' => '417.882.310-05'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('termo');

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }
}
