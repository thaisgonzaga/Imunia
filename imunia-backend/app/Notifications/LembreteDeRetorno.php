<?php

namespace App\Notifications;

use App\Models\Notificacao;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * RF43 — o lembrete do retorno programado no atendimento (RF34), na véspera.
 * A finalidade vai junto porque é ela que diz ao tutor o que levar: "trazer o
 * resultado do raspado" não se adivinha pela data.
 */
class LembreteDeRetorno extends LembreteAoTutor
{
    /** Fixado na construção, pelo motivo de `LembreteAoTutor::quando()`. */
    private string $quando;

    public function __construct(
        Notificacao $notificacao,
        string $tutor,
        string $animal,
        string $codigoDoAnimal,
        bool $acessoAtivado,
        private string $clinica,
        private ?string $finalidade,
    ) {
        parent::__construct($notificacao, $tutor, $animal, $codigoDoAnimal, $acessoAtivado);

        $this->quando = self::quando($notificacao->referente_a->toDateString());
    }

    public function toMail(object $notifiable): MailMessage
    {
        // A clínica é o sujeito da frase, e não um complemento: "na Clínica",
        // "no Hospital", "no Consultório" — o artigo depende de um nome que o
        // sistema não escolhe.
        $mensagem = $this->mensagem(sprintf('Retorno de %s %s', $this->animal, strtok($this->quando, ',')))
            ->line("{$this->clinica} marcou o retorno de {$this->animal} para {$this->quando}.");

        if (filled($this->finalidade)) {
            $mensagem->line("Finalidade: {$this->finalidade}");
        }

        $mensagem->line('Se não puder comparecer, avise a clínica para remarcar.');

        return $this->concluir($mensagem, "Ver {$this->animal}", "/animais/{$this->codigoDoAnimal}");
    }
}
