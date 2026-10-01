<?php

namespace App\Notifications;

use App\Models\Animal;
use App\Models\Prestador;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso ao tutor que já tem acesso: uma clínica cadastrou mais um animal em
 * nome dele. Quem ainda não ativou a conta recebe o convite no lugar deste
 * aviso (`ConviteDoTutorService`).
 */
class AnimalCadastradoPelaClinica extends Notification
{
    public function __construct(private Animal $animal, private Prestador $prestador) {}

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
            ->subject("{$this->animal->nome} foi cadastrado no Imunia")
            ->greeting('Novo animal no seu Imunia')
            ->line("{$this->prestador->nome} cadastrou {$this->animal->nome} no Imunia, junto dos seus outros animais.")
            ->line('Vacinas, atendimentos e documentos que a clínica registrar aparecem na sua conta.')
            ->action("Ver {$this->animal->nome}", $this->ligacao());
    }

    private function ligacao(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/animais/'.$this->animal->codigo;
    }
}
