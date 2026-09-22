<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAdministrado;
use App\Models\Convite;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * O convite de médico-veterinário para a equipe (A03, RF09, RN09).
 *
 * Três campos, os do desenho: endereço, CRMV e UF. O nome não é pedido aqui de
 * propósito — quem o informa é o próprio convidado, ao aceitar, junto com a
 * senha. Deixar o convidante batizar a conta alheia produz "Dr. Marcelo" onde a
 * pessoa assina "Marcelo Andrade Filho", e o nome carimba cada registro clínico
 * que ela vier a fazer.
 */
class ConvidarVeterinarioRequest extends FormRequest
{
    use ResolvePrestadorAdministrado;

    private ?Prestador $prestador = null;

    public function authorize(): bool
    {
        [, $this->prestador] = $this->contextoAdministrativo($this);

        return true;
    }

    public function prestador(): Prestador
    {
        return $this->prestador;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'crmv' => trim((string) $this->input('crmv')),
            'crmv_uf' => Str::upper(trim((string) $this->input('crmv_uf'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'crmv' => ['required', 'string', 'max:20', 'regex:/^\d+$/'],
            'crmv_uf' => ['required', 'string', 'in:'.implode(',', StorePrestadorRequest::UFS)],
        ];
    }

    public function messages(): array
    {
        return [
            'crmv.required' => 'Informe o CRMV: ele acompanha cada registro que este profissional fizer.',
            'crmv.regex' => 'Informe apenas o número da inscrição, sem “CRMV” e sem a UF.',
            'crmv_uf.required' => 'Informe a unidade federativa do CRMV.',
        ];
    }

    /**
     * As recusas saem como erro do campo `email`, e não como `abort`, porque
     * quem as vê está com o modal aberto: o realce tem de cair sobre o campo
     * que a pessoa acabou de preencher.
     *
     * O que **não** é recusa, e é deliberado: conta que já existe em outro
     * prestador (RF09 admite vínculo simultâneo), conta que só é tutor (RN05,
     * papéis acumuláveis) e vínculo encerrado aqui mesmo — reconvidar abre
     * vínculo novo e a linha encerrada permanece, porque ela é a prova de RF10.
     */
    public function after(): array
    {
        return [
            function (Validator $validador) {
                $email = (string) $this->input('email');

                if ($email === '' || $validador->errors()->has('email')) {
                    return;
                }

                $usuario = User::query()->where('email', $email)->first();

                if ($usuario === null) {
                    return;
                }

                if ($this->jaEstaNaEquipe($usuario)) {
                    $validador->errors()->add('email', 'Este profissional já faz parte da equipe.');

                    return;
                }

                if ($this->temConvitePendente($usuario)) {
                    $validador->errors()->add(
                        'email',
                        'Já existe um convite pendente para este endereço. Reenvie o convite pela lista da equipe.',
                    );
                }
            },
        ];
    }

    private function jaEstaNaEquipe(User $usuario): bool
    {
        return $this->prestador()->veterinariosAtivos()
            ->where('users.id', $usuario->id)
            ->exists();
    }

    private function temConvitePendente(User $usuario): bool
    {
        return Convite::query()
            ->where('prestador_id', $this->prestador()->id)
            ->where('user_id', $usuario->id)
            ->where('tipo', 'veterinario')
            ->whereNull('aceito_em')
            ->where('expira_em', '>', now())
            ->exists();
    }
}
