<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Imunobiologico;
use App\Models\Notificacao;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\Tutor;
use App\Models\Vacinacao;
use App\Notifications\LembreteDeDose;
use App\Notifications\LembreteDeRetorno;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * RF42, RF43 — a rotina diária de lembretes ao tutor, com as janelas fixadas
 * em 01/10/2026 (RN44): véspera da dose, quinto dia de atraso (uma única vez)
 * e véspera do retorno.
 */
class LembretesAoTutorTest extends TestCase
{
    use RefreshDatabase;

    private Imunobiologico $antirrabica;

    protected function setUp(): void
    {
        parent::setUp();

        // Meio da manhã em UTC, que é o relógio da aplicação: a data é a mesma
        // em Brasília, como na execução agendada das 8h.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 11:00:00'));

        $this->antirrabica = Imunobiologico::factory()->antirrabica()->create();
        ProtocoloVacinal::factory()->antirrabica()->create(['imunobiologico_id' => $this->antirrabica->id]);
    }

    private function animal(string $nome = 'Théo', ?Tutor $tutor = null): Animal
    {
        $tutor ??= Tutor::factory()->create(['nome' => 'Helena Ramos']);

        return Animal::factory()->create(['tutor_id' => $tutor->id, 'nome' => $nome]);
    }

    /**
     * Uma aplicação de antirrábica cujo reforço anual cai na data pedida: é a
     * data da aplicação que decide sozinha a data prevista.
     */
    private function reforcoPrevistoPara(Animal $animal, string $data): Vacinacao
    {
        return Vacinacao::factory()->create([
            'animal_id' => $animal->id,
            'imunobiologico_id' => $this->antirrabica->id,
            'protocolo_vacinal_id' => $this->antirrabica->protocoloVigente()->id,
            'aplicado_em' => CarbonImmutable::parse($data)->subYear()->setTime(9, 30),
        ]);
    }

    private function ativar(Tutor $tutor): void
    {
        $tutor->user->forceFill(['ativado_em' => now()->subMonth()])->save();
    }

    private function rodar(): void
    {
        $this->artisan('imunia:lembretes')->assertSuccessful();
    }

    /**
     * Um transporte de e-mail que recusa os endereços pedidos e aceita os
     * demais, no lugar do Brevo.
     *
     * @param  list<string>  $recusados
     */
    private function provedorQueRecusa(array $recusados): void
    {
        Mail::extend('recusa', fn () => new class($recusados) extends AbstractTransport
        {
            /** @param  list<string>  $recusados */
            public function __construct(private array $recusados)
            {
                parent::__construct();
            }

            public function send(RawMessage $mensagem, ?Envelope $envelope = null): ?SentMessage
            {
                $envelope ??= Envelope::create($mensagem);

                foreach ($envelope->getRecipients() as $destinatario) {
                    if (in_array($destinatario->getAddress(), $this->recusados, true)) {
                        throw new TransportException("Caixa inexistente: {$destinatario->getAddress()}");
                    }
                }

                return parent::send($mensagem, $envelope);
            }

            protected function doSend(SentMessage $mensagem): void {}

            public function __toString(): string
            {
                return 'recusa';
            }
        });

        config([
            'mail.mailers.recusa' => ['transport' => 'recusa'],
            'mail.default' => 'recusa',
        ]);

        // O gerenciador guarda o transporte já montado; sem isto, a segunda
        // chamada continuaria recusando os endereços da primeira.
        Mail::purge('recusa');
    }

    public function test_lembra_a_dose_na_vespera(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->reforcoPrevistoPara($theo, '2026-10-02');

        $this->rodar();

        $conta = $theo->tutor->user;
        Notification::assertSentTo($conta, LembreteDeDose::class, function (LembreteDeDose $lembrete) use ($conta) {
            $email = $lembrete->toMail($conta);

            return $email->subject === 'Vacina de Théo amanhã'
                && $email->greeting === 'Olá, Helena!'
                && str_contains($email->introLines[0], "{$this->antirrabica->nome_comercial} de Théo (reforço)")
                && str_contains($email->introLines[0], 'amanhã, 02/10/2026');
        });

        $this->assertDatabaseHas('notificacoes', [
            'animal_id' => $theo->id,
            'tutor_id' => $theo->tutor_id,
            'destinatario' => $conta->email,
            'imunobiologico_id' => $this->antirrabica->id,
            'tipo' => 'aviso_previo',
            'referente_a' => '2026-10-02',
            'situacao' => 'sem_confirmacao',
        ]);
    }

    public function test_nao_lembra_antes_da_vespera(): void
    {
        Notification::fake();
        $this->reforcoPrevistoPara($this->animal(), '2026-10-03');

        $this->rodar();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('notificacoes', 0);
    }

    /** RNF19 — o agendador e o disparo externo podem rodar no mesmo dia. */
    public function test_rodar_duas_vezes_no_mesmo_dia_nao_repete_o_lembrete(): void
    {
        Notification::fake();
        $this->reforcoPrevistoPara($this->animal(), '2026-10-02');

        $this->rodar();
        $this->rodar();

        Notification::assertSentTimes(LembreteDeDose::class, 1);
        $this->assertDatabaseCount('notificacoes', 1);
    }

    /**
     * Se a rotina não rodou na véspera, o lembrete sai no próprio dia, dizendo
     * "hoje". Passada a data, não sai mais: o próximo aviso é o de atraso.
     */
    public function test_o_lembrete_perdido_na_vespera_sai_no_dia_e_nunca_depois(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->reforcoPrevistoPara($theo, '2026-10-01');
        $this->reforcoPrevistoPara($this->animal('Mel'), '2026-09-30');

        $this->rodar();

        Notification::assertSentTimes(LembreteDeDose::class, 1);
        Notification::assertSentTo($theo->tutor->user, LembreteDeDose::class, fn (LembreteDeDose $lembrete) => $lembrete->toMail($theo->tutor->user)->subject === 'Vacina de Théo hoje');
    }

    public function test_o_alerta_de_atraso_sai_no_quinto_dia_uma_unica_vez(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->reforcoPrevistoPara($theo, '2026-09-26');

        $this->rodar();

        $conta = $theo->tutor->user;
        Notification::assertSentTo($conta, LembreteDeDose::class, function (LembreteDeDose $lembrete) use ($conta) {
            $email = $lembrete->toMail($conta);

            return $email->subject === 'A vacina de Théo está atrasada'
                && str_contains($email->introLines[0], 'estava prevista para 26/09/2026 e, 5 dias depois')
                && in_array('Este é o único aviso de atraso desta dose.', $email->introLines, true);
        });

        foreach (['2026-10-02', '2026-10-03', '2026-10-04', '2026-10-20'] as $dia) {
            $this->travelTo(CarbonImmutable::parse("{$dia} 11:00:00"));
            $this->rodar();
        }

        Notification::assertSentTimes(LembreteDeDose::class, 1);
        $this->assertDatabaseHas('notificacoes', ['tipo' => 'alerta_atraso', 'referente_a' => '2026-09-26']);
    }

    /**
     * A folga cobre dias sem rotina, e é também o que impede a primeira
     * execução de alertar todas as doses vencidas há meses.
     */
    public function test_o_alerta_perdido_ainda_sai_ate_o_setimo_dia_e_nunca_depois(): void
    {
        Notification::fake();
        $quatroDias = $this->animal('Bidu');
        $seteDias = $this->animal('Mel');
        $oitoDias = $this->animal('Nina');
        $meses = $this->animal('Rex');
        $this->reforcoPrevistoPara($quatroDias, '2026-09-27');
        $this->reforcoPrevistoPara($seteDias, '2026-09-24');
        $this->reforcoPrevistoPara($oitoDias, '2026-09-23');
        $this->reforcoPrevistoPara($meses, '2026-05-10');

        $this->rodar();

        Notification::assertSentTimes(LembreteDeDose::class, 1);
        Notification::assertSentTo($seteDias->tutor->user, LembreteDeDose::class, fn (LembreteDeDose $lembrete) => str_contains($lembrete->toMail($seteDias->tutor->user)->introLines[0], '7 dias depois'));
    }

    /** RF42c — com a aplicação registrada, a dose seguinte é outra. */
    public function test_a_aplicacao_registrada_cancela_o_alerta(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->reforcoPrevistoPara($theo, '2026-09-26');
        Vacinacao::factory()->create([
            'animal_id' => $theo->id,
            'imunobiologico_id' => $this->antirrabica->id,
            'protocolo_vacinal_id' => $this->antirrabica->protocoloVigente()->id,
            'aplicado_em' => now()->subDay(),
            'ordem_dose' => 2,
        ]);

        $this->rodar();

        Notification::assertNothingSent();
    }

    /**
     * RN42 — o tutor que não criou a senha recebe o lembrete, e a mensagem o
     * leva ao convite em vez de a uma carteira que ele não consegue abrir.
     */
    public function test_o_tutor_que_nao_ativou_o_acesso_recebe_o_lembrete(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->assertNull($theo->tutor->user->ativado_em);
        $this->reforcoPrevistoPara($theo, '2026-10-02');

        $this->rodar();

        $conta = $theo->tutor->user;
        Notification::assertSentTo($conta, LembreteDeDose::class, function (LembreteDeDose $lembrete) use ($conta) {
            $email = $lembrete->toMail($conta);

            return $email->actionUrl === null
                && collect($email->introLines)->contains(fn (string $linha) => str_contains($linha, 'crie sua senha pelo convite'));
        });
    }

    public function test_o_tutor_ativado_recebe_a_ligacao_para_a_carteira(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->ativar($theo->tutor);
        $this->reforcoPrevistoPara($theo, '2026-10-02');

        $this->rodar();

        $conta = $theo->tutor->user;
        Notification::assertSentTo($conta, LembreteDeDose::class, function (LembreteDeDose $lembrete) use ($conta, $theo) {
            $email = $lembrete->toMail($conta);

            return $email->actionText === 'Ver a carteira de Théo'
                && str_ends_with($email->actionUrl, "/animais/{$theo->codigo}/carteira");
        });
    }

    /**
     * RF06a — quem já usa a conta e trocou o e-mail só volta a receber depois
     * de confirmar o endereço novo.
     */
    public function test_o_tutor_ativado_com_email_novo_nao_confirmado_nao_recebe(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->ativar($theo->tutor);
        $theo->tutor->user->forceFill(['email_verified_at' => null])->save();
        $this->reforcoPrevistoPara($theo, '2026-10-02');

        $this->rodar();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('notificacoes', 0);
    }

    /** RF22a — o óbito encerra os lembretes, de dose e de retorno. */
    public function test_o_animal_com_obito_nao_gera_lembrete(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->reforcoPrevistoPara($theo, '2026-10-02');
        Atendimento::factory()->comRetorno('2026-10-02')->create(['animal_id' => $theo->id]);
        $theo->forceFill(['obito_em' => '2026-09-30'])->save();

        $this->rodar();

        Notification::assertNothingSent();
    }

    public function test_lembra_o_retorno_na_vespera(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $clinica = Prestador::factory()->create(['nome' => 'Clínica Vet Amigo']);
        $atendimento = Atendimento::factory()
            ->comRetorno('2026-10-02', 'Reavaliação com o resultado do raspado.')
            ->create(['animal_id' => $theo->id, 'prestador_id' => $clinica->id]);
        Atendimento::factory()->comRetorno('2026-10-03')->create(['animal_id' => $this->animal('Mel')->id]);

        $this->rodar();

        $conta = $theo->tutor->user;
        Notification::assertSentTimes(LembreteDeRetorno::class, 1);
        Notification::assertSentTo($conta, LembreteDeRetorno::class, function (LembreteDeRetorno $lembrete) use ($conta) {
            $email = $lembrete->toMail($conta);

            return $email->subject === 'Retorno de Théo amanhã'
                && $email->introLines[0] === 'Clínica Vet Amigo marcou o retorno de Théo para amanhã, 02/10/2026.'
                && $email->introLines[1] === 'Finalidade: Reavaliação com o resultado do raspado.';
        });

        $this->assertDatabaseHas('notificacoes', [
            'animal_id' => $theo->id,
            'imunobiologico_id' => null,
            'atendimento_id' => $atendimento->id,
            'tipo' => 'lembrete_retorno',
            'referente_a' => '2026-10-02',
        ]);
    }

    /**
     * RN43 — o evento é o retorno do animal naquela data, e não o atendimento
     * que o marcou: a retificação com a mesma data não manda um segundo aviso,
     * e a que muda a data cala o original.
     */
    public function test_o_retorno_retificado_nao_repete_nem_avisa_pela_versao_antiga(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $original = Atendimento::factory()->comRetorno('2026-10-02')->create(['animal_id' => $theo->id]);

        $this->rodar();
        Atendimento::factory()->retificando($original)->comRetorno('2026-10-02')->create();
        $this->rodar();

        Notification::assertSentTimes(LembreteDeRetorno::class, 1);

        $mel = $this->animal('Mel');
        $adiado = Atendimento::factory()->comRetorno('2026-10-02')->create(['animal_id' => $mel->id]);
        Atendimento::factory()->retificando($adiado)->comRetorno('2026-10-09')->create();

        $this->rodar();

        Notification::assertNotSentTo($mel->tutor->user, LembreteDeRetorno::class);
    }

    /**
     * RF42e, RNF18 — a recusa de um endereço fica registrada, não impede os
     * demais envios e é tentada de novo no dia seguinte, enquanto o aviso
     * estiver na janela.
     */
    public function test_a_falha_de_envio_fica_registrada_e_e_reenviada_no_dia_seguinte(): void
    {
        $theo = $this->animal();
        $mel = $this->animal('Mel', Tutor::factory()->create(['nome' => 'Rafael Souza']));
        $this->reforcoPrevistoPara($theo, '2026-09-26');
        $this->reforcoPrevistoPara($mel, '2026-09-26');
        $this->provedorQueRecusa([$theo->tutor->user->email]);

        $this->artisan('imunia:lembretes')
            ->expectsOutputToContain('alertas de atraso: 1 · lembretes de retorno: 0 · falhas: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('notificacoes', ['animal_id' => $theo->id, 'situacao' => 'falhou']);
        $this->assertDatabaseHas('notificacoes', ['animal_id' => $mel->id, 'situacao' => 'sem_confirmacao']);

        $this->provedorQueRecusa([]);
        $this->travelTo(CarbonImmutable::parse('2026-10-02 11:00:00'));

        $this->artisan('imunia:lembretes')
            ->expectsOutputToContain('alertas de atraso: 1 · lembretes de retorno: 0 · falhas: 0')
            ->assertSuccessful();

        $this->assertSame(1, Notificacao::where('animal_id', $theo->id)->count());
        $this->assertDatabaseHas('notificacoes', [
            'animal_id' => $theo->id,
            'situacao' => 'sem_confirmacao',
            'enviada_em' => '2026-10-02 11:00:00',
        ]);
    }

    /**
     * O disparo externo do plano gratuito: sem o segredo configurado, ou com o
     * segredo errado, a rota responde como se não existisse.
     */
    public function test_o_disparo_externo_exige_o_segredo(): void
    {
        Notification::fake();
        $this->reforcoPrevistoPara($this->animal(), '2026-10-02');

        $this->postJson('/api/rotinas/lembretes', [], ['Authorization' => 'Bearer qualquer'])->assertNotFound();

        config(['app.lembretes_token' => 'segredo-da-rotina']);

        $this->postJson('/api/rotinas/lembretes')->assertNotFound();
        $this->postJson('/api/rotinas/lembretes', [], ['Authorization' => 'Bearer outro'])->assertNotFound();
        Notification::assertNothingSent();

        $this->postJson('/api/rotinas/lembretes', [], ['Authorization' => 'Bearer segredo-da-rotina'])
            ->assertOk()
            ->assertJson([
                'executada' => true,
                'lembretes_de_dose' => 1,
                'alertas_de_atraso' => 0,
                'lembretes_de_retorno' => 0,
                'falhas' => 0,
            ]);
    }

    /** T17 — o lembrete de retorno aparece no histórico com a finalidade. */
    public function test_o_historico_do_tutor_mostra_a_finalidade_do_retorno(): void
    {
        Notification::fake();
        $theo = $this->animal();
        $this->ativar($theo->tutor);
        Atendimento::factory()->comRetorno('2026-10-02', 'Reavaliação com o resultado do raspado.')->create(['animal_id' => $theo->id]);

        $this->rodar();

        $this->actingAs($theo->tutor->user)
            ->getJson('/api/conta/notificacoes/enviadas')
            ->assertOk()
            ->assertJsonPath('notificacoes.0.tipo_rotulo', 'Lembrete de retorno')
            ->assertJsonPath('notificacoes.0.vacina', null)
            ->assertJsonPath('notificacoes.0.retorno.finalidade', 'Reavaliação com o resultado do raspado.')
            ->assertJsonPath('notificacoes.0.referente_a', '2026-10-02');
    }
}
