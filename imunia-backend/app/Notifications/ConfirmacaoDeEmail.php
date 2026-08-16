<?php

namespace App\Notifications;

use App\Models\EmailVerificationToken;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Comunicação transacional indispensável: não é alcançada pelo descadastro de
 * notificações (RN45).
 */
class ConfirmacaoDeEmail extends Notification
{
    public function __construct(private string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirme seu e-mail no Imunia')
            ->greeting('Confirme seu e-mail')
            ->line('Sem o endereço confirmado, não conseguimos avisar você sobre as próximas doses dos seus animais.')
            ->action('Confirmar meu e-mail', $this->ligacao())
            ->line(sprintf(
                'A ligação vale por %d horas. Se não foi você quem criou esta conta, ignore esta mensagem.',
                EmailVerificationToken::VALIDADE_EM_HORAS,
            ));
    }

    /**
     * A ligação leva à tela do SPA, e não à API: quem abre o e-mail precisa
     * cair numa tela que explique o resultado (P08).
     */
    private function ligacao(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/verificar-email/'.$this->token;
    }
}
