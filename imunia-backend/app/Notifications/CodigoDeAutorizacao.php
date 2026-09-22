<?php

namespace App\Notifications;

use App\Models\ConfirmacaoDeAutorizacao;
use App\Support\Enumeracao;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O código que efetiva a concessão (RF37). Comunicação transacional
 * indispensável: não é alcançada pelo descadastro de notificações (RN45).
 *
 * A mensagem diz o que está prestes a acontecer antes de dizer o código. Quem
 * recebe precisa poder reconhecer o pedido — ou estranhá-lo: um código sozinho,
 * sem o nome da clínica e do animal, não permitiria ao tutor perceber que a
 * autorização em curso não é a que ele imagina.
 */
class CodigoDeAutorizacao extends Notification
{
    /**
     * Públicos e imutáveis: é por eles que o teste confere que o código que
     * chega ao e-mail é o mesmo que a confirmação aceita — a única forma de
     * verificar isso sem que o código apareça em resposta alguma da API.
     *
     * @param  list<string>  $animais
     */
    public function __construct(
        public readonly string $codigo,
        public readonly string $prestador,
        public readonly array $animais,
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
            ->subject('Seu código para autorizar '.$this->prestador)
            ->greeting('Código para autorizar o acesso')
            ->line("Você pediu para autorizar **{$this->prestador}** a ver o histórico de **{$animais}**.")
            ->line("Seu código é **{$this->codigo}**.")
            ->line(sprintf(
                'Ele vale por %d minutos e só pode ser usado uma vez.',
                ConfirmacaoDeAutorizacao::VALIDADE_EM_MINUTOS,
            ))
            // A frase importa mais do que parece: é a defesa do tutor contra o
            // atendimento que conduz a concessão do balcão (RF37).
            ->line('Ninguém do Imunia nem da clínica pede este código. Se você não começou esta autorização, ignore esta mensagem — nada foi autorizado.');
    }
}
