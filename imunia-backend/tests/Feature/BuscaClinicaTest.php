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
 * V03 — buscar animal ou tutor (RF51, RF18, RF13).
 *
 * Metade destes testes não verifica o que a busca encontra, e sim o que ela se
 * recusa a dizer. É a tela de maior risco de vazamento por desenho de interface
 * do sistema, e a asserção que mais importa aqui é a de que o nome do tutor não
 * aparece em resposta alguma sem autorização vigente (RN12).
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
     * isso, e nada mais: nenhum animal, nenhuma existência, nenhum registro.
     */
    public function test_a_busca_sem_termo_devolve_apenas_o_contexto_clinico(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->autorizar($this->animalDe('Helena Ramos', 'Théo'), $clinica);

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

    /* Primeira seção — sob autorização (RN48) ------------------------------ */

    public function test_busca_por_nome_traz_o_animal_sob_autorizacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);

        $this->buscar($marcelo, 'Théo')
            ->assertOk()
            ->assertJsonPath('tipo', 'nome')
            ->assertJsonPath('estado', 'normal')
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.nome', 'Théo')
            ->assertJsonPath('autorizados.0.codigo', $theo->codigo)
            // Com autorização vigente, o nome de quem trouxe o animal pode
            // aparecer: é o que distingue as duas seções da tela.
            ->assertJsonPath('autorizados.0.tutor', 'Helena Ramos')
            ->assertJsonPath('existencia', null);
    }

    public function test_busca_por_nome_encontra_o_animal_pelo_nome_do_tutor(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->autorizar($this->animalDe('Helena Ramos', 'Théo'), $clinica);

        // No balcão, "a Helena" e "o Théo" identificam a mesma ficha.
        $this->buscar($marcelo, 'Helena')
            ->assertOk()
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.nome', 'Théo');
    }

    public function test_busca_por_codigo_do_animal_autorizado_traz_a_ficha_completa(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);

        $this->buscar($marcelo, $theo->codigo)
            ->assertOk()
            ->assertJsonPath('tipo', 'codigo')
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.tutor', 'Helena Ramos')
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
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);

        $solto = strtolower(str_replace('-', '', $theo->codigo));

        $this->buscar($marcelo, $solto)
            ->assertOk()
            ->assertJsonPath('tipo', 'codigo')
            ->assertJsonPath('autorizados.0.codigo', $theo->codigo);
    }

    public function test_busca_por_microchip_traz_o_animal_sob_autorizacao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $tutor = Tutor::factory()->create(['nome' => 'Helena Ramos']);
        $theo = Animal::factory()
            ->comMicrochip('076000000000042')
            ->create(['tutor_id' => $tutor->id, 'nome' => 'Théo']);
        $this->autorizar($theo, $clinica);

        $this->buscar($marcelo, '076000000000042')
            ->assertOk()
            ->assertJsonPath('tipo', 'microchip')
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('autorizados.0.nome', 'Théo');
    }

    public function test_a_busca_nao_mistura_prestadores(): void
    {
        $clinica = $this->clinica();
        $hospital = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($clinica, $hospital);
        $this->autorizar($this->animalDe('Helena Ramos', 'Théo'), $clinica);

        // O mesmo animal, o mesmo profissional, outro contexto ativo: fora da
        // clínica que o tutor autorizou, o Théo não é resultado (RN48).
        $this->buscar($marcelo, 'Théo', ['prestador' => $hospital->id])
            ->assertOk()
            ->assertJsonCount(0, 'autorizados');
    }

    public function test_autorizacao_revogada_ou_expirada_nao_traz_o_animal(): void
    {
        foreach (['revogada', 'expirada'] as $estado) {
            $clinica = $this->clinica("Clínica {$estado}");
            $marcelo = $this->marcelo($clinica);
            $theo = $this->animalDe('Helena Ramos', 'Théo');
            $this->autorizar($theo, $clinica, $estado);

            $this->buscar($marcelo, $theo->codigo)
                ->assertOk()
                ->assertJsonCount(0, 'autorizados', "autorização {$estado}")
                // Some da primeira seção e reaparece na segunda com o mínimo:
                // a autorização vencida não apaga o animal do mundo.
                ->assertJsonPath('existencia.tipo', 'animal');
        }
    }

    /* Segunda seção — só a existência (RN12, RF13, RF18) ------------------- */

    public function test_cpf_existente_sem_autorizacao_revela_apenas_a_existencia(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->animalDe('Helena Ramos', 'Théo', ['cpf' => self::CPF_VALIDO]);

        $resposta = $this->buscar($marcelo, '238.471.905-04')
            ->assertOk()
            ->assertJsonPath('tipo', 'cpf')
            ->assertJsonCount(0, 'autorizados')
            // RF13a — a tela informa apenas que existe cadastro para o CPF. A
            // pendência de solicitação é ato do próprio prestador (V10), não
            // dado do tutor — e aqui não há pedido feito.
            ->assertJsonPath('existencia', ['tipo' => 'tutor', 'solicitacao_pendente' => null]);

        // RF13b — nome, contato e relação de animais permanecem ocultos. A
        // asserção é sobre o corpo inteiro, e não sobre um campo: o vazamento
        // que importa é o que escapa por onde ninguém olhou.
        $this->assertStringNotContainsString('Helena Ramos', $resposta->getContent());
        $this->assertStringNotContainsString('Théo', $resposta->getContent());
    }

    public function test_codigo_sem_autorizacao_revela_apenas_nome_e_especie(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $mel = $this->animalDe('Antônio Prado', 'Mel', ['especie' => 'gato']);

        $resposta = $this->buscar($marcelo, $mel->codigo)
            ->assertOk()
            ->assertJsonCount(0, 'autorizados')
            // RF18a — espécie, nome e a indicação de histórico mediante
            // autorização, que é texto da tela e não dado do animal.
            ->assertJsonPath('existencia', [
                'tipo' => 'animal',
                'nome' => 'Mel',
                'especie' => 'gato',
                'solicitacao_pendente' => null,
            ]);

        $this->assertStringNotContainsString('Antônio Prado', $resposta->getContent());
    }

    public function test_microchip_sem_autorizacao_revela_apenas_nome_e_especie(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $tutor = Tutor::factory()->create(['nome' => 'Antônio Prado']);
        Animal::factory()
            ->comMicrochip('076000000000099')
            ->gato()
            ->create(['tutor_id' => $tutor->id]);

        $this->buscar($marcelo, '076000000000099')
            ->assertOk()
            ->assertJsonPath('tipo', 'microchip')
            ->assertJsonPath('existencia.tipo', 'animal')
            ->assertJsonPath('existencia.especie', 'gato');
    }

    /**
     * Por nome, a segunda seção não diz o que corresponde nem quantos: um
     * cartão só, seja qual for o número de cadastros alcançados. Cartão por
     * correspondência transformaria a busca em contador de quantas pessoas com
     * aquele nome existem na plataforma.
     */
    public function test_busca_por_nome_colapsa_os_cadastros_fora_do_ambito_em_um_cartao(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->animalDe('Helena Ramos', 'Théo');
        $this->animalDe('Helena Fontes', 'Bidu');

        $resposta = $this->buscar($marcelo, 'Helena')
            ->assertOk()
            ->assertJsonCount(0, 'autorizados')
            ->assertJsonPath('existencia', ['tipo' => 'outro']);

        $this->assertStringNotContainsString('Ramos', $resposta->getContent());
        $this->assertStringNotContainsString('Fontes', $resposta->getContent());
    }

    /**
     * Duas letras corresponderiam a meia base, e a segunda seção viraria
     * contador de cadastros — enumeração por outro nome.
     */
    public function test_nome_curto_nao_revela_cadastro_fora_do_ambito(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->animalDe('Helena Ramos', 'Bo');

        $this->buscar($marcelo, 'Bo')
            ->assertOk()
            ->assertJsonPath('existencia', null)
            ->assertJsonPath('estado', 'sem_resultado');

        $this->assertDatabaseCount('registros_de_acesso', 0);
    }

    /**
     * O tutor que já aparece na primeira seção não é anunciado de novo na
     * segunda: dizer que ele tem *outros* animais seria a contagem que RN12
     * proíbe.
     */
    public function test_tutor_ja_visivel_nao_gera_cartao_de_existencia(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo', ['cpf' => self::CPF_VALIDO]);
        $this->autorizar($theo, $clinica);

        // A Nina, do mesmo tutor, não está autorizada — e continua invisível.
        Animal::factory()->gato()->create(['tutor_id' => $theo->tutor_id]);

        $resposta = $this->buscar($marcelo, self::CPF_VALIDO)
            ->assertOk()
            ->assertJsonCount(1, 'autorizados')
            ->assertJsonPath('existencia', null);

        $this->assertStringNotContainsString('Nina', $resposta->getContent());
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

    public function test_a_consulta_por_cpf_sem_autorizacao_fica_registrada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo', ['cpf' => self::CPF_VALIDO]);

        $this->buscar($marcelo, self::CPF_VALIDO)->assertOk();

        // RF52 — usuário, prestador, natureza e data e hora. Sem animal: a
        // consulta por CPF não chegou a saber de animal algum.
        $this->assertDatabaseHas('registros_de_acesso', [
            'prestador_id' => $clinica->id,
            'user_id' => $marcelo->id,
            'tutor_id' => $theo->tutor_id,
            'animal_id' => null,
            'natureza' => RegistroDeAcesso::BUSCA_POR_CPF,
        ]);
    }

    public function test_a_consulta_por_codigo_sem_autorizacao_fica_registrada_com_o_animal(): void
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

    /**
     * Um cartão na tela, uma linha por titular alcançado: o log presta contas a
     * cada pessoa cuja existência foi revelada, ainda que a tela não as
     * distinga.
     */
    public function test_a_busca_por_nome_registra_uma_linha_por_titular_alcancado(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->animalDe('Helena Ramos', 'Théo');
        $this->animalDe('Helena Fontes', 'Bidu');

        $this->buscar($marcelo, 'Helena')->assertOk();

        $this->assertDatabaseCount('registros_de_acesso', 2);
    }

    /**
     * O acesso a quem já autorizou não é o acesso que RF18b manda registrar: a
     * autorização vigente é o próprio consentimento, e um log que anotasse toda
     * consulta rotineira afogaria em ruído a que importa (T14).
     */
    public function test_o_animal_sob_autorizacao_nao_gera_registro_de_acesso(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe('Helena Ramos', 'Théo');
        $this->autorizar($theo, $clinica);

        $this->buscar($marcelo, $theo->codigo)->assertOk();

        $this->assertDatabaseCount('registros_de_acesso', 0);
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
