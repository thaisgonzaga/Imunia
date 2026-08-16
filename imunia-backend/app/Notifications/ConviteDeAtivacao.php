<?php

namespace App\Notifications;

use App\Models\Convite;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convite de ativação de acesso (RF09, RF14). Comunicação transacional
 * indispensável: não é alcançada pelo descadastro de notificações (RN45).
 */
class ConviteDeAtivacao extends Notification
{
    public function __construct(private Convite $convite, private string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $prestador = $this->convite->prestador->nome;

        $mensagem = (new MailMessage)
            ->subject("Ative seu acesso no Imunia — convite de {$prestador}");

        if ($this->convite->tipo === 'veterinario') {
            $mensagem
                ->greeting('Convite de equipe')
                ->line("{$prestador} convidou você para atuar como médico-veterinário na plataforma.");
        } else {
            $mensagem
                ->greeting('Ative sua conta')
                ->line("{$prestador} criou uma conta para você no Imunia, onde fica o histórico de saúde dos seus animais.");
        }

        return $mensagem
            ->action('Ativar meu acesso', $this->ligacao())
            ->line(sprintf('O convite vale por %d dias.', Convite::VALIDADE_EM_DIAS));
    }

    private function ligacao(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/convite/'.$this->token;
    }
}
