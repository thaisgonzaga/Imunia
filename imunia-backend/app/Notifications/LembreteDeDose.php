<?php

namespace App\Notifications;

use App\Models\Notificacao;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * RF42 — as duas comunicações de uma dose prevista (RN44): o lembrete na
 * véspera e, se a aplicação não for registrada, o alerta no quinto dia de
 * atraso. Qual das duas é, diz a linha de `notificacoes`.
 */
class LembreteDeDose extends LembreteAoTutor
{
    /**
     * "amanhã, 02/10/2026" no lembrete; "5 dias" no alerta. Fixado na
     * construção, pelo motivo de `LembreteAoTutor::quando()`.
     */
    private string $prazo;

    /**
     * @param  string  $dose  o rótulo curto do calendário — "2ª dose", "reforço"
     */
    public function __construct(
        Notificacao $notificacao,
        string $tutor,
        string $animal,
        string $codigoDoAnimal,
        bool $acessoAtivado,
        private string $vacina,
        private string $dose,
    ) {
        parent::__construct($notificacao, $tutor, $animal, $codigoDoAnimal, $acessoAtivado);

        $this->prazo = $notificacao->tipo === 'alerta_atraso'
            ? ((int) CarbonImmutable::parse($notificacao->referente_a)->diffInDays(CarbonImmutable::today())).' dias'
            : self::quando($notificacao->referente_a->toDateString());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dose = "{$this->vacina} de {$this->animal} ({$this->dose})";

        if ($this->notificacao->tipo === 'alerta_atraso') {
            $mensagem = $this->mensagem("A vacina de {$this->animal} está atrasada")
                ->line(sprintf(
                    'A dose de %s estava prevista para %s e, %s depois, ainda não foi registrada no Imunia.',
                    $dose,
                    $this->notificacao->referente_a->format('d/m/Y'),
                    $this->prazo,
                ))
                ->line('Procure a clínica para colocar a vacina em dia. Se a dose já foi aplicada, peça à clínica que registre a aplicação.')
                // O tutor precisa conhecer a regra: o silêncio dos dias
                // seguintes não quer dizer que o atraso acabou.
                ->line('Este é o único aviso de atraso desta dose.');
        } else {
            $mensagem = $this->mensagem(sprintf('Vacina de %s %s', $this->animal, strtok($this->prazo, ',')))
                ->line("A próxima dose de {$dose} está prevista para {$this->prazo}.")
                ->line('Se a aplicação ainda não está combinada com a clínica, este é um bom momento.');
        }

        return $this->concluir($mensagem, "Ver a carteira de {$this->animal}", "/animais/{$this->codigoDoAnimal}/carteira");
    }
}
