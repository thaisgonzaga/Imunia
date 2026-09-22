<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\SolicitacaoAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\SolicitacaoDeAcessoRecebida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * V10 — solicitar autorização ao tutor (RF38), do lado de quem pede.
 *
 * O que estes testes protegem é o mesmo que os de T13, visto do outro balcão: o
 * pedido não abre porta alguma (RF38a), a resposta não diz do tutor nada além
 * do que a busca já disse (RN12) — nem quando o pedido, por dentro, não criou
 * linha nenhuma —, e o desdobramento por CPF continua nominal por animal
 * (RN37), porque quem responde é o tutor, um pedido de cada vez.
 */
class PedidoDeAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    /** CPF com dígitos verificadores corretos — o mesmo dos testes de V03. */
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

    /**
     * A tutora do cenário comum: conta ativada, apta a responder pelo app.
     */
    private function helena(string $cpf = self::CPF_VALIDO): Tutor
    {
        $usuario = User::factory()->create([
            'name' => 'Helena Ramos',
            'ativado_em' => now(),
        ]);

        return Tutor::factory()->create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
            'cpf' => $cpf,
        ]);
    }

    private function animalDe(Tutor $tutor, string $nome, string $especie = 'cao'): Animal
    {
        return Animal::factory()->create([
            'tutor_id' => $tutor->id,
            'nome' => $nome,
            'especie' => $especie,
        ]);
    }

    private function pedir(User $profissional, string $termo, array $extras = [])
    {
        return $this->actingAs($profissional)
            ->postJson('/api/clinica/solicitacoes', ['termo' => $termo, ...$extras]);
    }

    /* Porta de entrada ----------------------------------------------------- */

    public function test_o_pedido_exige_sessao(): void
    {
        $this->postJson('/api/clinica/solicitacoes', ['termo' => self::CPF_VALIDO])
            ->assertUnauthorized();
    }

    public function test_quem_nao_e_veterinario_nao_pede(): void
    {
        $helena = $this->helena();

        $this->pedir($helena->user, self::CPF_VALIDO)->assertForbidden();
    }

    /* O pedido pela chave do animal ----------------------------------------- */

    public function test_pedir_pelo_codigo_cria_o_pedido_e_avisa_o_tutor(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe($this->helena(), 'Théo');

        $this->pedir($marcelo, $theo->codigo, ['mensagem' => 'Atendimento de hoje no balcão.'])
            ->assertCreated()
            ->assertJsonPath('situacao', 'enviada')
            ->assertJsonPath('prazo_em_dias', SolicitacaoAcesso::PRAZO_DIAS)
            ->assertJsonPath('tutor_ativado', true);

        $pedido = SolicitacaoAcesso::query()->sole();
        $this->assertSame($theo->id, $pedido->animal_id);
        $this->assertSame($clinica->id, $pedido->prestador_id);
        $this->assertSame($marcelo->id, $pedido->solicitada_por_user_id);
        $this->assertSame('Atendimento de hoje no balcão.', $pedido->mensagem);
        $this->assertTrue($pedido->estaPendente());

        Notification::assertSentTo($theo->tutor->user, SolicitacaoDeAcessoRecebida::class);
    }

    public function test_a_resposta_nao_diz_nada_do_tutor(): void
    {
        // RF38a e RN12 — o pedido não é ocasião para saber mais. O corpo
        // inteiro é conferido, porque o vazamento que importa é o que escapa
        // por onde ninguém olhou.
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe($this->helena(), 'Théo');

        $resposta = $this->pedir($marcelo, $theo->codigo)->assertCreated();

        $this->assertStringNotContainsString('Helena', $resposta->getContent());
        $this->assertStringNotContainsString(self::CPF_VALIDO, $resposta->getContent());
        $this->assertStringNotContainsString('@', $resposta->getContent());
    }

    /* O pedido por CPF ------------------------------------------------------ */

    public function test_pedir_por_cpf_desdobra_um_pedido_por_animal(): void
    {
        // RN37 — não existe pedido ao plantel: por CPF, o que se cria é um
        // pedido nominal por animal, e quem escolhe a quais responder é o
        // tutor, um a um, em T13.
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = $this->animalDe($helena, 'Théo');
        $nina = $this->animalDe($helena, 'Nina', 'gato');

        $resposta = $this->pedir($marcelo, '238.471.905-04')
            ->assertCreated()
            ->assertJsonPath('situacao', 'enviada');

        $this->assertSame(2, SolicitacaoAcesso::query()->count());
        $this->assertEqualsCanonicalizing(
            [$theo->id, $nina->id],
            SolicitacaoAcesso::query()->pluck('animal_id')->all(),
        );

        // A resposta não conta nem nomeia: dois animais ou nenhum, a frase é a
        // mesma (RN12).
        $this->assertStringNotContainsString('Théo', $resposta->getContent());
        $this->assertStringNotContainsString('Nina', $resposta->getContent());
        $this->assertStringNotContainsString('2', $resposta->json('message'));

        // Um aviso só, nomeando os dois — o destinatário é o titular deles.
        Notification::assertSentToTimes($helena->user, SolicitacaoDeAcessoRecebida::class, 1);
        Notification::assertSentTo(
            $helena->user,
            SolicitacaoDeAcessoRecebida::class,
            fn (SolicitacaoDeAcessoRecebida $aviso) => $aviso->animais === ['Nina', 'Théo'],
        );
    }

    public function test_cpf_de_tutor_sem_animais_responde_como_se_tivesse_enviado(): void
    {
        // Distinguir "enviado" de "não havia a quem enviar" seria dizer que o
        // tutor não tem animais — a contagem que RN12 proíbe, no valor zero.
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $this->helena();

        $this->pedir($marcelo, self::CPF_VALIDO)
            ->assertCreated()
            ->assertJsonPath('situacao', 'enviada');

        $this->assertSame(0, SolicitacaoAcesso::query()->count());
        Notification::assertNothingSent();
    }

    public function test_por_cpf_o_animal_ja_pendente_nao_ganha_segundo_pedido(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $theo = $this->animalDe($helena, 'Théo');
        $nina = $this->animalDe($helena, 'Nina', 'gato');

        SolicitacaoAcesso::factory()->create([
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'solicitada_por_user_id' => $marcelo->id,
        ]);

        $this->pedir($marcelo, self::CPF_VALIDO)
            ->assertCreated()
            ->assertJsonPath('situacao', 'enviada');

        // Um pedido novo (Nina), e não dois: o do Théo continua o original.
        $this->assertSame(2, SolicitacaoAcesso::query()->count());
        $this->assertSame(1, SolicitacaoAcesso::query()->where('animal_id', $theo->id)->count());

        Notification::assertSentTo(
            $helena->user,
            SolicitacaoDeAcessoRecebida::class,
            fn (SolicitacaoDeAcessoRecebida $aviso) => $aviso->animais === ['Nina'],
        );
    }

    /* Estados de repetição -------------------------------------------------- */

    public function test_o_pedido_repetido_e_estado_e_nao_segunda_linha(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe($this->helena(), 'Théo');

        $this->pedir($marcelo, $theo->codigo)->assertCreated();

        $this->pedir($marcelo, $theo->codigo)
            ->assertStatus(409)
            ->assertJsonPath('situacao', 'ja_pendente')
            ->assertJsonPath('dias_restantes', SolicitacaoAcesso::PRAZO_DIAS);

        $this->assertSame(1, SolicitacaoAcesso::query()->count());
        Notification::assertSentToTimes($theo->tutor->user, SolicitacaoDeAcessoRecebida::class, 1);
    }

    public function test_animal_ja_autorizado_nao_recebe_pedido(): void
    {
        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe($this->helena(), 'Théo');

        Autorizacao::factory()->create([
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'concedida_por_user_id' => $theo->tutor->user_id,
        ]);

        $this->pedir($marcelo, $theo->codigo)
            ->assertStatus(409)
            ->assertJsonPath('situacao', 'ja_autorizada');

        $this->assertSame(0, SolicitacaoAcesso::query()->count());
    }

    public function test_o_pedido_caducado_admite_pedido_novo(): void
    {
        // RF38b — a caducidade encerra a espera, não o direito de pedir de
        // novo. O pedido novo é linha nova; o caducado permanece, porque o
        // histórico de quem pediu é informação do titular.
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe($this->helena(), 'Théo');

        SolicitacaoAcesso::factory()->expirada()->create([
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'solicitada_por_user_id' => $marcelo->id,
        ]);

        $this->pedir($marcelo, $theo->codigo)
            ->assertCreated()
            ->assertJsonPath('situacao', 'enviada');

        $this->assertSame(2, SolicitacaoAcesso::query()->count());
    }

    /* Tutor não ativado ------------------------------------------------------ */

    public function test_tutor_nao_ativado_recebe_o_pedido_mas_a_tela_fica_sabendo(): void
    {
        // RF14b — a conta criada no balcão existe e o pedido a espera em T13;
        // o que falta é o titular ativá-la, e é isso que a tela precisa dizer.
        // E-mail não sai: a comunicação pendente do titular é o convite.
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $naoAtivada = Tutor::factory()->create(['cpf' => self::CPF_VALIDO]);
        $this->animalDe($naoAtivada, 'Amora');

        $this->pedir($marcelo, self::CPF_VALIDO)
            ->assertCreated()
            ->assertJsonPath('situacao', 'enviada')
            ->assertJsonPath('tutor_ativado', false);

        $this->assertSame(1, SolicitacaoAcesso::query()->count());
        Notification::assertNothingSent();
    }

    /* O termo ---------------------------------------------------------------- */

    public function test_nome_nao_e_chave_de_pedido(): void
    {
        // Pelo nome, a correspondência é um conjunto indeterminado de
        // titulares — e pedido a esmo é varredura, não solicitação.
        $marcelo = $this->marcelo($this->clinica());

        $this->pedir($marcelo, 'Helena Ramos')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('termo');

        $this->assertSame(0, SolicitacaoAcesso::query()->count());
    }

    public function test_cpf_sem_cadastro_e_erro_de_campo(): void
    {
        $marcelo = $this->marcelo($this->clinica());

        $this->pedir($marcelo, self::CPF_VALIDO)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('termo');
    }

    public function test_cpf_com_digito_invalido_nem_consulta(): void
    {
        $marcelo = $this->marcelo($this->clinica());

        $this->pedir($marcelo, '417.882.310-05')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('termo');
    }

    /* O circuito com o tutor ------------------------------------------------- */

    public function test_a_mensagem_chega_ao_cartao_do_tutor(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $helena = $this->helena();
        $this->animalDe($helena, 'Théo');

        $this->pedir($marcelo, self::CPF_VALIDO, ['mensagem' => 'Atendimento de hoje no balcão.'])
            ->assertCreated();

        $this->actingAs($helena->user)
            ->getJson('/api/solicitacoes')
            ->assertOk()
            ->assertJsonPath('solicitacoes.0.mensagem', 'Atendimento de hoje no balcão.')
            ->assertJsonPath('solicitacoes.0.prestador.nome', 'Clínica Vet Amigo');
    }

    public function test_a_busca_passa_a_anunciar_a_pendencia(): void
    {
        // V03 troca o botão de pedir pela etiqueta de espera, e o dado que
        // sustenta isso é do próprio prestador: o pedido que ele fez, com data.
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe($this->helena(), 'Théo');

        $this->pedir($marcelo, $theo->codigo)->assertCreated();

        $this->actingAs($marcelo)
            ->getJson('/api/clinica/buscar?'.http_build_query(['termo' => $theo->codigo]))
            ->assertOk()
            ->assertJsonPath('existencia.tipo', 'animal')
            ->assertJsonPath(
                'existencia.solicitacao_pendente.dias_restantes',
                SolicitacaoAcesso::PRAZO_DIAS,
            );
    }

    public function test_a_ficha_sem_autorizacao_anuncia_a_pendencia(): void
    {
        Notification::fake();

        $clinica = $this->clinica();
        $marcelo = $this->marcelo($clinica);
        $theo = $this->animalDe($this->helena(), 'Théo');

        $this->pedir($marcelo, $theo->codigo)->assertCreated();

        $this->actingAs($marcelo)
            ->getJson("/api/clinica/animais/{$theo->codigo}")
            ->assertOk()
            ->assertJsonPath('acesso', 'sem_autorizacao')
            ->assertJsonPath(
                'solicitacao_pendente.dias_restantes',
                SolicitacaoAcesso::PRAZO_DIAS,
            );
    }
}
