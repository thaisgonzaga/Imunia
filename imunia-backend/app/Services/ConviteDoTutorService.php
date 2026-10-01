<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Convite;
use App\Models\Prestador;
use App\Models\User;
use App\Notifications\AnimalCadastradoPelaClinica;

/**
 * O que o tutor recebe quando uma clínica cadastra um animal dele.
 *
 * O convite de acesso sai com o animal, e não com o cadastro do tutor: é o pet
 * que dá sentido à mensagem ("seu pet foi cadastrado no Imunia"), e um tutor
 * cadastrado sem animal não tem nada a acompanhar. Nada do atendimento espera
 * por isso — o tutor entra se quiser, quando quiser.
 *
 * - Tutor que ainda não ativou a conta recebe o convite (RF14). Se já havia um
 *   pendente, ele é reemitido: token novo, prazo renovado, e a ligação do
 *   e-mail anterior deixa de valer (RF14a).
 * - Tutor que já tem acesso recebe só o aviso do animal novo.
 */
class ConviteDoTutorService
{
    /**
     * @return array{tipo: 'convite'|'aviso'|null, email: string|null}
     */
    public function avisarCadastroDeAnimal(Animal $animal, Prestador $prestador, User $profissional): array
    {
        $usuario = $animal->tutor->user;

        if ($usuario === null) {
            return ['tipo' => null, 'email' => null];
        }

        if ($usuario->ativado_em !== null) {
            // RN42 — só endereço verificado recebe notificação.
            if ($usuario->email_verified_at === null) {
                return ['tipo' => null, 'email' => null];
            }

            $usuario->notify(new AnimalCadastradoPelaClinica($animal, $prestador));

            return ['tipo' => 'aviso', 'email' => $usuario->email];
        }

        $pendente = Convite::query()
            ->where('user_id', $usuario->id)
            ->where('tipo', 'tutor')
            ->whereNull('aceito_em')
            ->latest('id')
            ->first();

        if ($pendente !== null) {
            $pendente->forceFill([
                'prestador_id' => $prestador->id,
                'convidado_por' => $profissional->id,
            ]);
            $token = $pendente->reemitir();
            $convite = $pendente;
        } else {
            [$convite, $token] = Convite::emitir($usuario, $prestador, 'tutor', $profissional);
        }

        $convite->enviar($token, $animal);

        return ['tipo' => 'convite', 'email' => $usuario->email];
    }
}
