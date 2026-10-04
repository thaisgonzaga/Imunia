<?php

namespace App\Notifications;

use App\Models\Notificacao;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * O que os lembretes ao tutor (RF42, RF43) têm em comum: saem pela fila, cada
 * um corresponde a uma linha de `notificacoes` já gravada, e a falha de envio
 * fica escrita nessa linha (RF42e, RNF18).
 *
 * Diferente do convite e da confirmação de e-mail, não é comunicação
 * transacional: é o que o descadastro de RN45 alcançará quando T16 existir.
 */
abstract class LembreteAoTutor extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * RNF18 — a nova tentativa, dentro do mesmo dia. Esgotadas as três, a linha
     * fica como "não entregue", e a rotina do dia seguinte ainda a reenvia se o
     * aviso continuar dentro da janela (`LembretesAoTutorService`).
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    /**
     * @param  bool  $acessoAtivado  quem não criou a senha não tem carteira a
     *                               abrir: a mensagem aponta para o convite
     */
    public function __construct(
        protected Notificacao $notificacao,
        protected string $tutor,
        protected string $animal,
        protected string $codigoDoAnimal,
        protected bool $acessoAtivado,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Chamado pela fila quando as tentativas se esgotam — e, com a fila
     * síncrona do plano gratuito, logo na primeira falha. "Não entregue" é o
     * que V02 mostra para dizer à clínica que o telefonema é a única via
     * restante.
     */
    public function failed(Throwable $erro): void
    {
        $this->notificacao->forceFill(['situacao' => 'falhou'])->save();
    }

    /**
     * Cumprimento e encerramento comuns. O cumprimento usa só o primeiro nome:
     * "Olá, Helena Ramos!" soa como cobrança.
     */
    protected function mensagem(string $assunto): MailMessage
    {
        return (new MailMessage)
            ->subject($assunto)
            ->greeting('Olá, '.strtok(trim($this->tutor), ' ').'!');
    }

    /**
     * Quem ativou o acesso abre a página do animal; quem não ativou recebe o
     * lembrete mesmo assim (RN42) e é lembrado de que existe uma senha a criar.
     */
    protected function concluir(MailMessage $mensagem, string $rotuloDaAcao, string $caminho): MailMessage
    {
        if ($this->acessoAtivado) {
            $mensagem->action($rotuloDaAcao, rtrim((string) config('app.frontend_url'), '/').$caminho);
        } else {
            $mensagem->line("Para acompanhar pela internet as vacinas e os atendimentos de {$this->animal}, crie sua senha pelo convite que a clínica enviou para este endereço.");
        }

        return $mensagem->line("Você recebe este e-mail porque este endereço está cadastrado no Imunia como contato do tutor de {$this->animal}.");
    }

    /**
     * "amanhã, 02/10/2026" ou "hoje, 02/10/2026". Calculado por quem constrói a
     * mensagem, no dia da rotina, e não por `toMail()`: na fila, o envio pode
     * acontecer depois, e o "amanhã" de ontem é o "hoje" de hoje. O dia da
     * semana não entra — a data é o que não envelhece na caixa de entrada.
     */
    protected static function quando(string $data): string
    {
        $dia = CarbonImmutable::parse($data);

        return ($dia->isToday() ? 'hoje' : 'amanhã').", {$dia->format('d/m/Y')}";
    }
}
