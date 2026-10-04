<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Convite;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\AnimalCadastradoPelaClinica;
use App\Notifications\ConviteDeAtivacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * V05 — cadastrar animal no atendimento (RF16, RF19, RF20).
 *
 * O que estes testes protegem: o cadastro nasce vinculado ao tutor certo (o
 * CPF com dígito conferido, nunca um id), a caracterização carimba autor e
 * data (RF19c) e põe o animal na carteira da clínica, a duplicidade alerta
 * antes de criar (RF20a) — e, fora da carteira, fica registrada (RF18b) —, e a
 * consolidação completa o cadastro preliminar sem jamais criar um segundo
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

    /**
     * Cadastrar e atender são o mesmo balcão: o animal cadastrado aqui já é
     * da carteira da clínica, com a origem que diz como entrou nela.
     */
    public function test_o_cadastro_poe_o_animal_na_carteira_da_clinica(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->helena();

        $this->cadastrar($marcelo)->assertCreated();

        $animal = Animal::query()->sole();

        $this->assertTrue($clinica->acompanha($animal));
        $this->assertDatabaseHas('animal_prestador', [
            'animal_id' => $animal->id,
            'prestador_id' => $clinica->id,
            'origem' => Prestador::VINCULO_POR_CADASTRO,
        ]);
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

    public function test_o_tutor_cadastrado_sem_cpf_e_alcancado_pelo_email(): void
    {
        $marcelo = $this->marcelo($this->clinica());
        $conta = User::factory()->create(['email' => 'helena@example.com']);
        $helena = Tutor::factory()->for($conta)->create(['cpf' => null]);

        $this->actingAs($marcelo)->postJson('/api/clinica/animais', [
            'email' => 'Helena@example.com',
            'nome' => 'Théo',
            'especie' => 'cao',
        ])->assertCreated();

        $this->assertSame($helena->id, Animal::query()->sole()->tutor_id);
    }

    public function test_email_sem_cadastro_manda_cadastrar_o_tutor_primeiro(): void
    {
        $marcelo = $this->marcelo($this->clinica());

        $this->actingAs($marcelo)->postJson('/api/clinica/animais', [
            'email' => 'ninguem@example.com',
            'nome' => 'Théo',
            'especie' => 'cao',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(0, Animal::query()->count());
    }

    public function test_sem_email_nem_cpf_nao_ha_tutor(): void
    {
        $marcelo = $this->marcelo($this->clinica());

        $this->actingAs($marcelo)->postJson('/api/clinica/animais', [
            'nome' => 'Théo',
            'especie' => 'cao',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
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

    public function test_duplicidade_fora_da_carteira_traz_o_cartao_e_fica_registrada(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Theo', 'especie' => 'cao']);

        $resposta = $this->cadastrar($marcelo)->assertStatus(409);

        // O cartão completo, com o código que leva à ficha: o tutor está no
        // balcão, e o caminho é atender o cadastro que já existe.
        $resposta->assertJsonPath('duplicado.codigo', $theo->codigo);
        $resposta->assertJsonPath('duplicado.nome', 'Theo');
        $resposta->assertJsonPath('duplicado.especie', 'cao');
        $resposta->assertJsonMissingPath('duplicado.ambito');

        // RF18b por analogia: o encontro de animal que a clínica não
        // acompanhava presta contas ao titular.
        $registro = RegistroDeAcesso::query()->sole();
        $this->assertSame(RegistroDeAcesso::ALERTA_DE_DUPLICIDADE, $registro->natureza);
        $this->assertSame($helena->id, $registro->tutor_id);
        $this->assertSame($theo->id, $registro->animal_id);

        $this->assertSame(1, Animal::query()->count());

        // O alerta não é ficha aberta: não vincula.
        $this->assertFalse($clinica->acompanha($theo));
    }

    public function test_duplicidade_na_carteira_traz_o_cartao_completo_sem_registro(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo', 'especie' => 'cao']);
        $clinica->vincular($theo);

        $resposta = $this->cadastrar($marcelo)->assertStatus(409);

        $resposta->assertJsonPath('duplicado.codigo', $theo->codigo);
        $resposta->assertJsonMissingPath('duplicado.ambito');

        // O que a clínica já acompanha não é revelação, e não gera linha
        // (mesma condição da busca de V03).
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

    /**
     * O código é identificador exato: caracterizar alcança qualquer animal,
     * e alcançá-lo já o põe na carteira da clínica.
     */
    public function test_caracterizar_animal_sem_vinculo_o_alcanca_e_vincula(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $preliminar = Animal::factory()->create(['tutor_id' => $this->helena()->id]);

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$preliminar->codigo}/caracterizar", ['raca' => 'SRD'])
            ->assertOk();

        $this->assertFalse($preliminar->fresh()->preliminar());
        $this->assertDatabaseHas('animal_prestador', [
            'animal_id' => $preliminar->id,
            'prestador_id' => $clinica->id,
            'origem' => Prestador::VINCULO_POR_ATENDIMENTO,
        ]);
    }

    public function test_caracterizar_codigo_inexistente_e_404(): void
    {
        $marcelo = $this->marcelo($this->clinica());

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/animais/IM-9Z9Z-9Z9Z/caracterizar')
            ->assertNotFound();

        $this->assertDatabaseCount('animal_prestador', 0);
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
        $clinica->vincular($theo);

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

    /**
     * RF19 — "completar e *manter*": a mesma porta serve depois da primeira
     * vez. O que o teste protege é o que a manutenção não pode fazer — criar
     * um segundo cadastro (RN19) ou conservar a assinatura antiga sobre o
     * dado novo (RF19c).
     */
    public function test_caracterizar_de_novo_reescreve_o_dado_e_a_autoria(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $joana = User::factory()->create(['name' => 'Joana Lima']);
        $joana->prestadores()->attach($clinica, ['papel' => 'veterinario', 'crmv' => '54321', 'crmv_uf' => 'MG']);

        $theo = Animal::factory()->caracterizado()->create([
            'tutor_id' => $this->helena()->id,
            'nome' => 'Théo',
            'especie' => 'cao',
            'raca' => 'SRD',
            'pelagem' => 'caramelo',
            'situacao_reprodutiva' => 'inteiro',
        ]);
        $theo->forceFill([
            'caracterizado_em' => now()->subMonths(3),
            'caracterizado_por_user_id' => $joana->id,
        ])->save();
        $clinica->vincular($theo);

        // A leitura diz o que está gravado e por quem — é o que a tela mostra
        // antes de oferecer a reescrita.
        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}/caracterizar")
            ->assertOk()
            ->assertJsonPath('animal.preliminar', false)
            ->assertJsonPath('animal.raca', 'SRD')
            ->assertJsonPath('animal.caracterizado_por', 'Joana Lima');

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$theo->codigo}/caracterizar", [
                'raca' => 'Labrador',
                'pelagem' => 'preta',
                'situacao_reprodutiva' => 'castrado',
                'microchip' => '076000000000123',
            ])
            ->assertOk()
            ->assertJsonPath('animal.preliminar', false);

        $atualizado = $theo->fresh();

        $this->assertSame(1, Animal::query()->count());
        $this->assertSame('Labrador', $atualizado->raca);
        $this->assertSame('preta', $atualizado->pelagem);
        $this->assertSame('castrado', $atualizado->situacao_reprodutiva);
        $this->assertSame('076000000000123', $atualizado->microchip);

        // RF19c — toda alteração registra autor, data e hora: a assinatura
        // passa a ser de quem alterou, não de quem preencheu primeiro.
        $this->assertSame($marcelo->id, $atualizado->caracterizado_por_user_id);
        $this->assertTrue($atualizado->caracterizado_em->isSameDay(now()));
    }

    /**
     * Salvar sem trocar o micro-chip não pode acusar conflito do animal com
     * ele mesmo — a coluna é única, e a manutenção reenviaria o número.
     */
    public function test_manter_o_proprio_microchip_nao_e_conflito(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = Animal::factory()->caracterizado()->comMicrochip('076000000000123')->create([
            'tutor_id' => $this->helena()->id,
            'raca' => 'SRD',
        ]);
        $clinica->vincular($theo);

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$theo->codigo}/caracterizar", [
                'raca' => 'Poodle',
                'microchip' => '076000000000123',
            ])
            ->assertOk();

        $this->assertSame('Poodle', $theo->fresh()->raca);
        $this->assertSame('076000000000123', $theo->fresh()->microchip);
    }

    public function test_a_caracterizacao_aparece_no_perfil_do_tutor(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = Animal::factory()->create(['tutor_id' => $helena->id, 'nome' => 'Théo', 'especie' => 'cao']);
        $clinica->vincular($theo);

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

    /* O tutor fica sabendo ------------------------------------------------ */

    public function test_o_primeiro_animal_leva_o_convite_ao_tutor_nao_ativado(): void
    {
        Notification::fake();

        $primeira = $this->clinica();
        $segunda = $this->clinica('Hospital Veterinário Central');
        $marcelo = $this->marcelo($primeira, $segunda);
        $helena = $this->helena();

        // RF09b — o convite sai em nome do contexto escolhido na faixa, não do
        // primeiro vínculo: é o nome dele que o tutor lê no e-mail.
        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais?prestador={$segunda->id}", [
                'cpf' => self::CPF_VALIDO,
                'nome' => 'Théo',
                'especie' => 'cao',
            ])
            ->assertCreated()
            ->assertJsonPath('tutor_avisado.tipo', 'convite')
            ->assertJsonPath('tutor_avisado.email', $helena->user->email);

        $convite = Convite::query()->sole();
        $this->assertSame('tutor', $convite->tipo);
        $this->assertSame($helena->user_id, $convite->user_id);
        $this->assertSame($segunda->id, $convite->prestador_id);
        $this->assertSame($marcelo->id, $convite->convidado_por);

        Notification::assertSentTo(
            $helena->user,
            ConviteDeAtivacao::class,
            function (ConviteDeAtivacao $notificacao) use ($helena) {
                $mensagem = $notificacao->toMail($helena->user);

                return $mensagem->subject === 'Théo foi cadastrado no Imunia'
                    && $mensagem->actionText === 'Criar minha senha';
            },
        );
    }

    public function test_o_segundo_animal_reemite_o_convite_pendente(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();

        $this->cadastrar($marcelo)->assertCreated();
        $primeiro = Convite::query()->sole();
        $tokenAnterior = $primeiro->token;

        $this->travel(3)->days();

        $this->cadastrar($marcelo, ['nome' => 'Nina', 'especie' => 'gato'])
            ->assertCreated()
            ->assertJsonPath('tutor_avisado.tipo', 'convite');

        // RF14a — um convite só: token novo, prazo contado de novo, e a
        // ligação do primeiro e-mail deixa de valer.
        $convite = Convite::query()->sole();
        $this->assertNotSame($tokenAnterior, $convite->token);
        $this->assertTrue($convite->expira_em->isAfter(now()->addDays(Convite::VALIDADE_EM_DIAS - 1)));

        Notification::assertSentToTimes($helena->user, ConviteDeAtivacao::class, 2);
    }

    public function test_tutor_que_ja_tem_acesso_recebe_so_o_aviso(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $helena->user->forceFill(['ativado_em' => now()])->save();

        $this->cadastrar($marcelo)
            ->assertCreated()
            ->assertJsonPath('tutor_avisado.tipo', 'aviso');

        $this->assertSame(0, Convite::query()->count());
        Notification::assertSentTo($helena->user, AnimalCadastradoPelaClinica::class);
        Notification::assertNotSentTo($helena->user, ConviteDeAtivacao::class);
    }

    public function test_tutor_ativo_sem_email_verificado_nao_recebe_aviso(): void
    {
        // RN42 — só endereço verificado recebe notificação.
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $helena->user->forceFill(['ativado_em' => now(), 'email_verified_at' => null])->save();

        $this->cadastrar($marcelo)
            ->assertCreated()
            ->assertJsonPath('tutor_avisado.tipo', null);

        Notification::assertNothingSent();
    }

    public function test_a_caracterizacao_nao_envia_nada_ao_tutor(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = Animal::factory()->create(['tutor_id' => $this->helena()->id]);

        $this->actingAs($marcelo)
            ->postJson("/api/clinica/animais/{$theo->codigo}/caracterizar", [
                'sexo' => 'macho',
                'nascimento' => '04/06/2024',
                'nascimento_exato' => true,
            ])
            ->assertOk();

        Notification::assertNothingSent();
    }
}
