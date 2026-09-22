<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V05 — cadastrar animal no atendimento (RF16, RF19, RF20).
 *
 * O que estes testes protegem: o cadastro nasce vinculado ao tutor certo (o
 * CPF com dígito conferido, nunca um id), a caracterização carimba autor e
 * data (RF19c), a duplicidade alerta antes de criar (RF20a) sem revelar fora
 * do âmbito mais do que RF18a admite — e revelando fica registrada (RF18b) —,
 * e a consolidação completa o cadastro preliminar sem jamais criar um segundo
 * (RN19).
 */
class CadastroDeAnimalNaClinicaTest extends TestCase
{
    use RefreshDatabase;

    /** CPF com dígitos verificadores corretos — o mesmo dos testes de V03/V04. */
    private const CPF_VALIDO = '23847190504';

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

    private function helena(string $cpf = self::CPF_VALIDO): Tutor
    {
        return Tutor::factory()->create(['nome' => 'Helena Ramos', 'cpf' => $cpf]);
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
     * @param  array<string, mixed>  $extras
     */
    private function cadastrar(User $profissional, array $extras = [])
    {
        return $this->actingAs($profissional)->postJson('/api/clinica/animais', [
            'cpf' => '238.471.905-04',
            'nome' => 'Théo',
            'especie' => 'cao',
            ...$extras,
        ]);
    }

    /* Porta de entrada ----------------------------------------------------- */

    public function test_o_cadastro_exige_sessao(): void
    {
        $this->postJson('/api/clinica/animais', [])->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_cadastra_por_aqui(): void
    {
        // Corpo válido de propósito: a validação vem antes da guarda de papel,
        // e um corpo incompleto responderia 422 sem nunca chegar a ela.
        $helena = $this->helena();

        $this->actingAs($helena->user)
            ->postJson('/api/clinica/animais', [
                'cpf' => self::CPF_VALIDO,
                'nome' => 'Théo',
                'especie' => 'cao',
            ])
            ->assertForbidden();

        $this->assertSame(0, Animal::query()->count());
    }

    /* O cadastro ------------------------------------------------------------ */

    public function test_cadastra_com_identificacao_e_caracterizacao_de_uma_vez(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();

        $resposta = $this->cadastrar($marcelo, [
            'sexo' => 'macho',
            'nascimento' => '04/06/2024',
            'nascimento_exato' => true,
            'raca' => 'SRD',
            'pelagem' => 'caramelo',
            'situacao_reprodutiva' => 'castrado',
            'microchip' => '981098106548712',
        ])->assertCreated();

        $animal = Animal::query()->sole();

        $this->assertSame($helena->id, $animal->tutor_id);
        $this->assertMatchesRegularExpression('/^IM-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $animal->codigo);
        $this->assertSame('2024-06-04', $animal->nascimento_em->toDateString());
        $this->assertTrue($animal->nascimento_exato);
        $this->assertSame('SRD', $animal->raca);
        $this->assertSame('981098106548712', $animal->microchip);

        // O cadastro feito por veterinário nunca foi preliminar (RN17 fala do
        // iniciado pelo tutor), e o autor fica carimbado (RF19c).
        $this->assertFalse($animal->preliminar());
        $this->assertSame($marcelo->id, $animal->caracterizado_por_user_id);

        $resposta->assertJsonPath('animal.codigo', $animal->codigo);

        // A resposta confirma o que o profissional digitou — e nada do tutor.
        $this->assertStringNotContainsString('Helena', $resposta->getContent());
    }

    public function test_nascimento_estimado_guarda_o_primeiro_dia_e_a_imprecisao(): void
    {
        $marcelo = $this->marcelo($this->clinica());
        $this->helena();

        $this->cadastrar($marcelo, ['nascimento' => '06/2024'])->assertCreated();

        $animal = Animal::query()->sole();
        $this->assertSame('2024-06-01', $animal->nascimento_em->toDateString());
        $this->assertFalse($animal->nascimento_exato);
    }

    public function test_data_exata_exige_dia_mes_e_ano(): void
    {
        $marcelo = $this->marcelo($this->clinica());
        $this->helena();

        $this->cadastrar($marcelo, ['nascimento' => '06/2024', 'nascimento_exato' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('nascimento');
    }

    public function test_cpf_sem_cadastro_manda_cadastrar_o_tutor_primeiro(): void
    {
        $marcelo = $this->marcelo($this->clinica());

        $this->cadastrar($marcelo)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cpf');

        $this->assertSame(0, Animal::query()->count());
    }

    public function test_cpf_com_digito_invalido_nem_consulta(): void
    {
        $marcelo = $this->marcelo($this->clinica());

        $this->cadastrar($marcelo, ['cpf' => '417.882.310-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cpf');
    }

    public function test_especie_fora_de_cao_e_gato_e_recusada(): void
    {
        $marcelo = $this->marcelo($this->clinica());
        $this->helena();

        $this->cadastrar($marcelo, ['especie' => 'coelho'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('especie');
    }

    /* Duplicidade (RF20a) ---------------------------------------------------- */

    public function test_duplicidade_fora_do_ambito_revela_o_minimo_e_fica_registrada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Theo', 'especie' => 'cao']);

        $resposta = $this->cadastrar($marcelo)->assertStatus(409);

        // O mínimo de RF18a: nome e espécie. Sem código — o alerta não pode
        // virar a busca que o profissional não fez.
        $resposta->assertJsonPath('duplicado.nome', 'Theo');
        $resposta->assertJsonPath('duplicado.especie', 'cao');
        $resposta->assertJsonPath('duplicado.ambito', 'fora_do_ambito');
        $this->assertStringNotContainsString($theo->codigo, $resposta->getContent());

        // RF18b por analogia: a revelação presta contas ao titular.
        $registro = RegistroDeAcesso::query()->sole();
        $this->assertSame(RegistroDeAcesso::ALERTA_DE_DUPLICIDADE, $registro->natureza);
        $this->assertSame($helena->id, $registro->tutor_id);
        $this->assertSame($theo->id, $registro->animal_id);

        $this->assertSame(1, Animal::query()->count());
    }

    public function test_duplicidade_no_ambito_traz_o_cartao_completo_sem_registro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo', 'especie' => 'cao']);
        $this->autorizar($theo, $clinica);

        $resposta = $this->cadastrar($marcelo)->assertStatus(409);

        $resposta->assertJsonPath('duplicado.codigo', $theo->codigo);
        $resposta->assertJsonPath('duplicado.ambito', 'autorizado');

        // O que o âmbito já mostra não é revelação, e não gera linha (mesma
        // condição da busca de V03).
        $this->assertSame(0, RegistroDeAcesso::query()->count());
    }

    public function test_ciente_da_duplicidade_o_segundo_envio_cadastra(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Theo', 'especie' => 'cao']);

        $this->cadastrar($marcelo, ['confirmar_duplicidade' => true])->assertCreated();

        $this->assertSame(2, Animal::query()->count());
    }

    public function test_microchip_igual_e_duplicidade_mesmo_com_nome_diferente(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        Animal::factory()->create([
            'tutor_id' => $helena->id,
            'nome' => 'Amora',
            'especie' => 'gato',
            'microchip' => '981098106548712',
        ]);

        $this->cadastrar($marcelo, ['microchip' => '981098106548712'])
            ->assertStatus(409)
            ->assertJsonPath('duplicado.nome', 'Amora');
    }

    public function test_microchip_de_cadastro_alheio_e_erro_de_campo(): void
    {
        $marcelo = $this->marcelo($this->clinica());
        $this->helena();

        $outra = Tutor::factory()->create();
        $alheio = Animal::factory()->create(['tutor_id' => $outra->id, 'microchip' => '981098106548712']);

        $resposta = $this->cadastrar($marcelo, ['microchip' => '981098106548712'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('microchip');

        // A recusa não diz de quem é nem o que é (RN12).
        $this->assertStringNotContainsString($alheio->nome, $resposta->getContent());
    }

    /* Consolidação (RF19, RF20b) --------------------------------------------- */

    public function test_caracterizar_exige_autorizacao_vigente(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $preliminar = Animal::factory()->create(['tutor_id' => $this->helena()->id]);

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$preliminar->codigo}/caracterizar")
            ->assertForbidden();

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$preliminar->codigo}/caracterizar", ['raca' => 'SRD'])
            ->assertForbidden();

        $this->assertTrue($preliminar->fresh()->preliminar());
    }

    public function test_caracterizar_completa_o_preliminar_sem_segundo_cadastro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = Animal::factory()->create([
            'tutor_id' => $helena->id,
            'nome' => 'Théo',
            'especie' => 'cao',
            'sexo' => 'macho',
            'nascimento_em' => '2024-06-01',
            'nascimento_exato' => false,
        ]);
        $this->autorizar($theo, $clinica);

        $opcoes = $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/caracterizar")
            ->assertOk();

        // RF19b — o declarado pelo tutor vem para confirmação ou correção.
        $opcoes->assertJsonPath('animal.preliminar', true);
        $opcoes->assertJsonPath('animal.sexo', 'macho');
        $opcoes->assertJsonPath('animal.tutor', 'Helena Ramos');

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$theo->codigo}/caracterizar", [
                'sexo' => 'macho',
                'nascimento' => '04/06/2024',
                'nascimento_exato' => true,
                'raca' => 'SRD',
                'pelagem' => 'caramelo',
                'situacao_reprodutiva' => 'inteiro',
            ])
            ->assertOk()
            ->assertJsonPath('animal.preliminar', false);

        $atualizado = $theo->fresh();

        // RN19 — consolida-se o registro existente, nunca um segundo.
        $this->assertSame(1, Animal::query()->count());
        $this->assertSame($theo->codigo, $atualizado->codigo);

        $this->assertFalse($atualizado->preliminar());
        $this->assertTrue($atualizado->nascimento_exato);
        $this->assertSame('2024-06-04', $atualizado->nascimento_em->toDateString());
        $this->assertSame($marcelo->id, $atualizado->caracterizado_por_user_id);
    }

    public function test_a_caracterizacao_aparece_no_perfil_do_tutor(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo', 'especie' => 'cao']);
        $this->autorizar($theo, $clinica);

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$theo->codigo}/caracterizar", [
                'raca' => 'SRD',
                'pelagem' => 'caramelo',
            ])
            ->assertOk();

        // T04 — o EmptyState dá lugar ao bloco real, com autor e data (RF19c).
        $this->actingAs($helena->user)
            ->getJson("/api/animais/{$theo->codigo}")
            ->assertOk()
            ->assertJsonPath('preliminar', false)
            ->assertJsonPath('caracterizacao.raca', 'SRD')
            ->assertJsonPath('caracterizacao.caracterizado_por', 'Marcelo Andrade');
    }

    public function test_o_tutor_nao_alcanca_a_caracterizacao(): void
    {
        // RF19a — os campos são inacessíveis à escrita pelo tutor também na
        // API: a rota é do ambiente clínico, e o papel barra na porta.
        $helena = $this->helena();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id]);

        $this->actingAs($helena->user)
            ->postJson("/api/clinica/animais/{$theo->codigo}/caracterizar", ['raca' => 'SRD'])
            ->assertForbidden();

        $this->assertTrue($theo->fresh()->preliminar());
    }
}
