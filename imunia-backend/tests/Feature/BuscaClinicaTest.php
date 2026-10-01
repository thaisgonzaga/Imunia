<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V03 — buscar animal ou tutor (RF51, RF18, RF13).
 *
 * Duas portas, com alcances diferentes: o identificador exato (CPF, código,
 * micro-chip) traz o cadastro inteiro, seja de que clínica for, e o encontro de
 * animal fora da carteira fica registrado (RF18b); o nome só alcança os animais
 * que a clínica já acompanha, e não registra nada.
 */
class BuscaClinicaTest extends TestCase
{
    use RefreshDatabase;

    /** CPF com dígitos verificadores corretos — o mesmo do cenário de demonstração. */
    private const CPF_VALIDO = '23847190504';

    /** O exemplo de dígito verificador incorreto que a própria tela exibe. */
    private const CPF_INVALIDO = '41788231005';

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
        $tutor = Tutor::factory()->create([
            'nome' => $nomeDoTutor,
            ...array_key_exists('cpf', $atributos) ? ['cpf' => $atributos['cpf']] : [],
        ]);

        return Animal::factory()->create([
            ...array_diff_key($atributos, ['cpf' => null]),
            'tutor_id' => $tutor->id,
            'nome' => $nome,
        ]);
    }

    private function vincular(Animal $animal, Prestador $prestador): void
    {
        $prestador->vincular($animal);
    }

    private function buscar(User $usuario, string $termo, array $extras = [])
    {
        return $this->actingAs($usuario)
            ->getJson('/api/clinica/buscar?'.http_build_query(['termo' => $termo, ...$extras]));
    }

    /* Porta de entrada ----------------------------------------------------- */

    public function test_a_busca_exige_sessao(): void
    {
        $this->getJson('/api/clinica/buscar?termo=Helena')->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_alcanca_a_busca(): void
    {
        $tutor = Tutor::factory()->create();

        $this->buscar($tutor->user, 'Helena')->assertForbidden();
    }

    public function test_prestador_sem_vinculo_com_o_profissional_e_recusado(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');

        $this->buscar($this->marcelo($clinica), 'Helena', ['prestador' => $hospital->id])
            ->assertForbidden();
    }

    /**
     * A tela recém-aberta ainda não perguntou nada, e precisa saber em que
     * prestador está para desenhar a faixa de contexto. Termo vazio devolve
     * isso, e nada mais: nenhum animal, nenhum registro.
     */
    public function test_a_busca_sem_termo_devolve_apenas_o_contexto_clinico(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->vincular($this->animalDe('Helena Ramos', 'Théo'), $clinica);

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/buscar')
            ->assertOk()
            ->assertJsonPath('estado', 'inicial')
            ->assertJsonPath('prestador.nome', 'Clínica Vet Amigo')
            ->assertJsonCount(1, 'vinculos')
            ->assertJsonCount(0, 'autorizados')
            ->assertJsonPath('existencia', null);

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /* Busca por nome — só a carteira do prestador ------------------------- */

    public function test_busca_por_nome_traz_o_animal_vinculado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($theo, $clinica);

        $this->buscar($marcelo, 'Théo')
            ->assertOk()
            ->assertJsonPath('tipo', 'nome')
            ->assertJsonPath('estado', 'normal')
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.nome', 'Théo')
            ->assertJsonPath('autorizados.0.codigo', $theo->codigo)
            ->assertJsonPath('autorizados.0.tutor', 'Helena Ramos')
            ->assertJsonPath('autorizados.0.vinculado', true)
            ->assertJsonPath('existencia', null);
    }

    public function test_busca_por_nome_encontra_o_animal_pelo_nome_do_tutor(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->vincular($this->animalDe('Helena Ramos', 'Théo'), $clinica);

        // No balcão, "a Helena" e "o Théo" identificam a mesma ficha.
        $this->buscar($marcelo, 'Helena')
            ->assertOk()
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.nome', 'Théo');
    }

    /**
     * Nome não identifica ninguém: a busca por nome em toda a base seria
     * varredura de dados de terceiros. Fora da carteira, nada aparece — nem
     * cartão de existência, nem contagem.
     */
    public function test_busca_por_nome_nao_alcanca_animal_de_fora_da_carteira(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->animalDe('Helena Ramos', 'Théo');
        $this->animalDe('Helena Fontes', 'Bidu');

        $resposta = $this->buscar($marcelo, 'Helena')
            ->assertOk()
            ->assertJsonPath('estado', 'sem_resultado')
            ->assertJsonCount(0, 'autorizados')
            ->assertJsonPath('existencia', null);

        $this->assertStringNotContainsString('Ramos', $resposta->getContent());
        $this->assertStringNotContainsString('Fontes', $resposta->getContent());
    }

    public function test_a_busca_por_nome_nao_mistura_prestadores(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($clinica, $hospital);
        $this->vincular($this->animalDe('Helena Ramos', 'Théo'), $clinica);

        // O mesmo animal, o mesmo profissional, outro contexto ativo: fora da
        // carteira do hospital, o Théo não é resultado por nome.
        $this->buscar($marcelo, 'Théo', ['prestador' => $hospital->id])
            ->assertOk()
            ->assertJsonCount(0, 'autorizados');
    }

    /**
     * A busca por nome só olha a própria carteira, e o que a clínica já
     * acompanha não é acesso que RF18b mande registrar.
     */
    public function test_a_busca_por_nome_nao_registra_acesso(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->vincular($this->animalDe('Helena Ramos', 'Théo'), $clinica);
        $this->animalDe('Helena Fontes', 'Bidu');

        $this->buscar($marcelo, 'Helena')->assertOk();

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /* Identificador exato — alcança qualquer cadastro ---------------------- */

    public function test_busca_por_codigo_do_animal_vinculado_traz_o_cartao_completo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($theo, $clinica);

        $this->buscar($marcelo, $theo->codigo)
            ->assertOk()
            ->assertJsonPath('tipo', 'codigo')
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.tutor', 'Helena Ramos')
            ->assertJsonPath('autorizados.0.vinculado', true)
            ->assertJsonPath('existencia', null);
    }

    /**
     * O código é ditado ao balcão e digitado por quem não o está vendo: a
     * pontuação e a caixa não podem decidir se ele é encontrado.
     */
    public function test_o_codigo_e_reconhecido_sem_hifens_e_em_minusculas(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        // Código fixo e com algarismo: sem hífen, é o algarismo que distingue o
        // código de um nome começado por "Im" (`TermoDeBusca`), e um código
        // sorteado só com letras tornaria o teste intermitente.
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $theo->forceFill(['codigo' => 'IM-4B8T-77LX'])->save();
        $this->vincular($theo, $clinica);

        $solto = strtolower(str_replace('-', '', $theo->codigo));

        $this->buscar($marcelo, $solto)
            ->assertOk()
            ->assertJsonPath('tipo', 'codigo')
            ->assertJsonPath('autorizados.0.codigo', $theo->codigo);
    }

    /**
     * Quem tem o código na mão está com o animal à sua frente: a resposta traz
     * o cadastro inteiro, seja de que clínica for. Buscar não vincula — quem
     * põe o animal na carteira é a ficha aberta.
     */
    public function test_codigo_de_animal_sem_vinculo_traz_o_cartao_completo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $mel = $this->animalDe('Antônio Prado', 'Mel', ['especie' => 'gato']);

        $this->buscar($marcelo, $mel->codigo)
            ->assertOk()
            ->assertJsonPath('estado', 'normal')
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.nome', 'Mel')
            ->assertJsonPath('autorizados.0.especie', 'gato')
            ->assertJsonPath('autorizados.0.tutor', 'Antônio Prado')
            ->assertJsonPath('autorizados.0.vinculado', false)
            ->assertJsonPath('existencia', null);

        $this->assertDatabaseMissing('animal_prestador', [
            'animal_id' => $mel->id,
            'prestador_id' => $clinica->id,
        ]);
    }

    public function test_microchip_de_animal_sem_vinculo_traz_o_cartao_completo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $tutor = Tutor::factory()->create(['nome' => 'Antônio Prado']);
        Animal::factory()
            ->comMicrochip('076000000000099')
            ->gato()
            ->create(['tutor_id' => $tutor->id, 'nome' => 'Mel']);

        $this->buscar($marcelo, '076000000000099')
            ->assertOk()
            ->assertJsonPath('tipo', 'microchip')
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.nome', 'Mel')
            ->assertJsonPath('autorizados.0.especie', 'gato')
            ->assertJsonPath('autorizados.0.tutor', 'Antônio Prado')
            ->assertJsonPath('autorizados.0.vinculado', false)
            ->assertJsonPath('existencia', null);
    }

    /**
     * Pelo CPF o encontrado é o titular: todos os animais dele vêm, os que a
     * clínica já acompanha e os que ainda não, cada um com a sua marca.
     */
    public function test_cpf_traz_todos_os_animais_do_tutor_com_a_marca_de_vinculo(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo', ['cpf' => self::CPF_VALIDO]);
        $this->vincular($theo, $clinica);
        Animal::factory()->gato()->create(['tutor_id' => $theo->tutor_id, 'nome' => 'Nina']);

        $this->buscar($marcelo, '238.471.905-04')
            ->assertOk()
            ->assertJsonPath('tipo', 'cpf')
            ->assertJsonCount(2, 'autorizados')
            // Ordenados por nome.
            ->assertJsonPath('autorizados.0.nome', 'Nina')
            ->assertJsonPath('autorizados.0.vinculado', false)
            ->assertJsonPath('autorizados.1.nome', 'Théo')
            ->assertJsonPath('autorizados.1.vinculado', true)
            ->assertJsonPath('autorizados.1.tutor', 'Helena Ramos')
            ->assertJsonPath('tutor.nome', 'Helena Ramos')
            ->assertJsonPath('existencia', null);
    }

    public function test_cpf_de_tutor_sem_animal_devolve_o_titular_e_fica_registrado(): void
    {
        // V04 e V05 precisam seguir para o cadastro do primeiro animal de um
        // tutor que ainda não tem nenhum.
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = Tutor::factory()->create(['nome' => 'Helena Ramos', 'cpf' => self::CPF_VALIDO]);

        $this->buscar($marcelo, '238.471.905-04')
            ->assertOk()
            ->assertJsonPath('estado', 'normal')
            ->assertJsonCount(0, 'autorizados')
            ->assertJsonPath('tutor.nome', 'Helena Ramos');

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'tutor_id' => $helena->id,
            'animal_id' => null,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CPF,
        ]);
    }

    public function test_busca_por_codigo_nao_devolve_titular(): void
    {
        $marcelo = $this->marcelo($this->clinica());
        $theo = $this->animalDe('Helena Ramos', 'Théo');

        $this->buscar($marcelo, $theo->codigo)
            ->assertOk()
            ->assertJsonPath('tutor', null);
    }

    public function test_termo_sem_correspondencia_alguma_devolve_sem_resultado(): void
    {
        $marcelo = $this->marcelo($this->clinica());

        $this->buscar($marcelo, 'IM-9Z1Q-00AB')
            ->assertOk()
            ->assertJsonPath('tipo', 'codigo')
            ->assertJsonPath('estado', 'sem_resultado')
            ->assertJsonPath('existencia', null)
            ->assertJsonCount(0, 'autorizados');
    }

    /* Registro de acesso (RF18b, RF52) ------------------------------------- */

    public function test_a_consulta_por_cpf_de_tutor_fora_da_carteira_fica_registrada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo', ['cpf' => self::CPF_VALIDO]);
        Animal::factory()->gato()->create(['tutor_id' => $theo->tutor_id]);

        $this->buscar($marcelo, self::CPF_VALIDO)->assertOk();

        // RF52 — usuário, prestador, natureza e data e hora. Sem animal: o
        // encontrado pelo CPF é o titular. Uma linha só, ainda que dois
        // animais tenham vindo.
        $this->assertDatabaseCount('registros_de_acesso', 1);
        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $theo->tutor_id,
            'animal_id' => null,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CPF,
        ]);
    }

    public function test_a_consulta_por_codigo_de_animal_sem_vinculo_fica_registrada_com_o_animal(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $mel = $this->animalDe('Antônio Prado', 'Mel', ['especie' => 'gato']);

        $this->buscar($marcelo, $mel->codigo)->assertOk();

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $mel->tutor_id,
            'animal_id' => $mel->id,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CODIGO,
        ]);
    }

    public function test_a_consulta_por_microchip_de_animal_sem_vinculo_fica_registrada_com_o_animal(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $tutor = Tutor::factory()->create(['nome' => 'Antônio Prado']);
        $mel = Animal::factory()
            ->comMicrochip('076000000000099')
            ->gato()
            ->create(['tutor_id' => $tutor->id]);

        $this->buscar($marcelo, '076000000000099')->assertOk();

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $tutor->id,
            'animal_id' => $mel->id,
            'natureza' => RegistroDeAcesso::BUSCA_POR_MICROCHIP,
        ]);
    }

    /**
     * O acesso a quem a clínica já acompanha não é o acesso que RF18b manda
     * registrar: um log que anotasse toda consulta rotineira afogaria em
     * ruído a que importa (T14).
     */
    public function test_o_animal_vinculado_nao_gera_registro_de_acesso(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($theo, $clinica);

        $this->buscar($marcelo, $theo->codigo)->assertOk();

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /**
     * O vínculo é da clínica, e não do profissional: o mesmo animal, buscado
     * pelo código no contexto de outro prestador, é encontro registrado ali.
     */
    public function test_vinculo_com_outra_clinica_nao_dispensa_o_registro(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($clinica, $hospital);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->vincular($theo, $clinica);

        $this->buscar($marcelo, $theo->codigo, ['prestador' => $hospital->id])
            ->assertOk()
            ->assertJsonPath('autorizados.0.vinculado', false);

        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $hospital->id,
            'animal_id' => $theo->id,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CODIGO,
        ]);
    }

    /**
     * A promessa que a tela faz nesse estado: "nenhuma consulta foi feita e
     * nada foi registrado". Um número digitado errado é o CPF de outra pessoa,
     * e não pode produzir registro de acesso no nome dela.
     */
    public function test_cpf_com_digito_invalido_nao_consulta_nem_registra(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->animalDe('Helena Ramos', 'Théo', ['cpf' => self::CPF_VALIDO]);

        $this->buscar($marcelo, self::CPF_INVALIDO)
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.termo.0',
                'Este CPF não é válido: o dígito verificador não confere. Confira o número com o tutor.',
            );

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }
}
