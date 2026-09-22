<?php

namespace App\Notifications;

use App\Models\SolicitacaoAcesso;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O pedido de acesso, comunicado ao titular (RF38): quem solicitou e para qual
 * animal. É a notificação que o requisito exige — e ela conduz a T13, onde a
 * resposta acontece, em vez de oferecer qualquer atalho de concessão por
 * e-mail: autorizar continua sendo o fluxo de T11, com código (RF37).
 *
 * Os animais vêm nomeados porque o destinatário é o próprio tutor — nada aqui
 * revela dado a terceiro. A mensagem do prestador, quando houver, vai citada e
 * identificada como dele: é contexto para reconhecer o pedido, não texto da
 * plataforma.
 */
class SolicitacaoDeAcessoRecebida extends Notification
{
    /**
     * @param  list<string>  $animais
     */
    public function __construct(
        public readonly string $prestador,
        public readonly array $animais,
        public readonly ?string $mensagem,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $nomes = $this->enumerar($this->animais);

        $email = (new MailMessage)
            ->subject("Pedido de acesso — {$this->prestador}")
            ->greeting('Um prestador pediu acesso ao histórico')
            ->line("**{$this->prestador}** pediu sua autorização para acompanhar o histórico de **{$nomes}**.");

        if ($this->mensagem !== null) {
            $email->line("Mensagem do prestador: “{$this->mensagem}”");
        }

        return $email
            ->line('Enquanto você não autorizar, o prestador não vê nada sobre seus animais. Recusar também é uma resposta, e não exige justificativa.')
            ->action('Responder ao pedido', $this->ligacao())
            ->line(sprintf(
                'O pedido espera resposta por %d dias. Depois disso, caduca sozinho.',
                SolicitacaoAcesso::PRAZO_DIAS,
            ));
    }

    private function ligacao(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/solicitacoes';
    }

    /**
     * "Théo", "Théo e Nina", "Théo, Nina e Amora" — a lista como se diz.
     *
     * @param  list<string>  $nomes
     */
    private function enumerar(array $nomes): string
    {
        if (count($nomes) <= 1) {
            return $nomes[0] ?? 'seus animais';
        }

        $ultimo = array_pop($nomes);

        return implode(', ', $nomes).' e '.$ultimo;
    }
}
