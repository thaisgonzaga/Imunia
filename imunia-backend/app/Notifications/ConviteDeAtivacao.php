<?php

namespace App\Notifications;

use App\Models\Animal;
use App\Models\Convite;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Convite de ativação de acesso (RF09, RF14). Comunicação transacional
 * indispensável: não é alcançada pelo descadastro de notificações (RN45).
 */
class ConviteDeAtivacao extends Notification
{
    public function __construct(
        private Convite $convite,
        private string $token,
        private ?Animal $animal = null,
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
        $prestador = $this->convite->prestador->nome;

        if ($this->convite->tipo === 'veterinario') {
            return (new MailMessage)
                ->subject("Ative seu acesso no Imunia — convite de {$prestador}")
                ->greeting('Convite de equipe')
                ->line("{$prestador} convidou você para atuar como médico-veterinário na plataforma.")
                ->action('Ativar meu acesso', $this->ligacao())
                ->line(sprintf('O convite vale por %d dias.', Convite::VALIDADE_EM_DIAS));
        }

        // O tutor não precisa fazer nada para ser atendido: o convite é a
        // oferta de acompanhar o que a clínica registra, e diz isso.
        $mensagem = (new MailMessage)
            ->subject($this->animal !== null
                ? "{$this->animal->nome} foi cadastrado no Imunia"
                : "Acompanhe seus animais no Imunia — convite de {$prestador}")
            ->greeting('Olá!')
            ->line($this->animal !== null
                ? "{$prestador} cadastrou {$this->animal->nome} no Imunia, a plataforma que a clínica usa para registrar vacinas, atendimentos e exames."
                : "{$prestador} usa o Imunia para registrar vacinas, atendimentos e exames dos seus animais.")
            ->line('Se quiser acompanhar tudo o que for registrado, crie sua senha pelo botão abaixo. Não é preciso fazer mais nada: o atendimento não depende disso.');

        return $mensagem
            ->action('Criar minha senha', $this->ligacao())
            ->line(sprintf('O convite vale por %d dias. Se ele vencer, a própria página oferece um novo.', Convite::VALIDADE_EM_DIAS));
    }

    private function ligacao(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/convite/'.$this->token;
    }
}
