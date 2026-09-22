<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * RF39c — a revogação, comunicada ao prestador. Comunicação transacional de
 * quem perdeu um acesso, não notificação de conveniência: não é alcançada pelo
 * descadastro de RN45.
 *
 * A mensagem diz o que o prestador ainda pode fazer antes de dizer o que já não
 * pode. Sem essa ordem, a clínica lê "acesso encerrado" e conclui que perdeu o
 * próprio prontuário — que continua sob a guarda dela por obrigação perante o
 * Conselho Federal de Medicina Veterinária (RN40).
 *
 * O motivo não viaja porque não existe: RF39 dispensa justificativa, e inventar
 * uma linha de explicação convidaria a clínica a cobrá-la do tutor.
 */
class AutorizacaoRevogada extends Notification
{
    public function __construct(
        public readonly string $prestador,
        public readonly string $animal,
        public readonly string $codigoDoAnimal,
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
            ->subject("Autorização encerrada — {$this->animal}")
            ->greeting('Uma autorização de acesso foi encerrada')
            ->line("O tutor de **{$this->animal}** ({$this->codigoDoAnimal}) encerrou a autorização de acesso ao histórico do animal em **{$this->prestador}**.")
            ->line('Os atendimentos e as vacinações registradas por vocês continuam disponíveis e sob a guarda do estabelecimento, como exige a legislação profissional.')
            ->line('O que deixa de aparecer é o histórico produzido por outros prestadores.')
            ->line('Para voltar a acompanhar o histórico completo, peça ao tutor uma nova autorização.');
    }
}
