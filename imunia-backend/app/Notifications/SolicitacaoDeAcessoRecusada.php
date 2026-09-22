<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O desfecho do pedido de acesso, comunicado a quem o fez (RF38).
 *
 * A mensagem diz uma coisa só: o pedido não foi aceito. Não há motivo porque
 * não existe motivo registrado — RF38 não o exige do tutor, e uma linha de
 * explicação inventada aqui viraria, no balcão, uma pergunta ao tutor sobre uma
 * decisão que é dele e não precisa ser justificada.
 *
 * O animal é nomeado porque a clínica precisa saber a qual pedido a resposta se
 * refere; o código único não vai junto pela mesma razão de RF13 — quem não tem
 * autorização não recebe identificador de animal alheio.
 */
class SolicitacaoDeAcessoRecusada extends Notification
{
    public function __construct(
        public readonly string $prestador,
        public readonly string $animal,
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
        return (new MailMessage)
            ->subject("Pedido de acesso não aceito — {$this->animal}")
            ->greeting('O tutor não aceitou o pedido de acesso')
            ->line("O pedido de **{$this->prestador}** para acompanhar o histórico de **{$this->animal}** não foi aceito pelo tutor.")
            ->line('Os registros que o estabelecimento produziu para este animal continuam disponíveis e sob a guarda de vocês.')
            ->line('O tutor pode autorizar o acesso a qualquer momento, e não é necessário fazer um novo pedido para isso.');
    }
}
