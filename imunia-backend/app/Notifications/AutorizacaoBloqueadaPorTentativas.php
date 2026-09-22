<?php

namespace App\Notifications;

use App\Models\ConfirmacaoDeAutorizacao;
use App\Support\Enumeracao;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * O aviso que acompanha a pausa por tentativas erradas (RF37a), prometido em
 * tela no estado de bloqueio de T11: "enviamos um aviso para o seu e-mail com a
 * data e a hora das tentativas".
 *
 * A tela sozinha não bastaria. Se quem errou os códigos não era o tutor, é
 * justamente porque ele não está diante da tela que precisa ser avisado — e
 * pelo canal que só ele abre.
 */
class AutorizacaoBloqueadaPorTentativas extends Notification
{
    /**
     * @param  list<string>  $animais
     */
    public function __construct(
        private string $prestador,
        private array $animais,
        private Carbon $ocorridoEm,
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
        $animais = Enumeracao::emPortugues($this->animais);

        return (new MailMessage)
            ->subject('Bloqueamos uma autorização no Imunia')
            ->greeting('Houve códigos errados numa autorização sua')
            ->line(sprintf(
                'Em %s, houve %d tentativas seguidas com o código errado ao autorizar **%s** a ver o histórico de **%s**.',
                $this->ocorridoEm->format('d/m/Y \à\s H\hi'),
                ConfirmacaoDeAutorizacao::TENTATIVAS,
                $this->prestador,
                $animais,
            ))
            // O que não aconteceu vem antes do que fazer: é a informação que
            // tira o susto de quem abre a mensagem.
            ->line('**Nada foi autorizado.** A clínica continua sem ver o histórico.')
            ->line(sprintf(
                'Por segurança, novas tentativas ficam pausadas por %d minutos.',
                ConfirmacaoDeAutorizacao::BLOQUEIO_EM_MINUTOS,
            ))
            ->line('Se foi você quem digitou, é só recomeçar quando a pausa terminar. Se não foi, troque a sua senha do Imunia.');
    }
}
