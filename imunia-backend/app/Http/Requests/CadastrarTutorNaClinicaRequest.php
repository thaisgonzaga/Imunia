<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * V04 — cadastrar tutor no atendimento (RF12).
 *
 * Nome e e-mail, e nada mais: o e-mail é a chave do cadastro (RN05, um
 * endereço, uma conta) e o destino do convite; o CPF não é pedido no balcão.
 * Só a forma do que foi digitado é conferida aqui. A existência de cadastro —
 * e-mail que já é de um tutor — é decidida no controlador, porque não é defeito
 * do que se digitou: é estado da plataforma, e conduz ao fluxo de RF13.
 *
 * Sem senha, de propósito: quem a define é o titular, no aceite do convite de
 * ativação (RF14). Sem aceite de termos pelo mesmo motivo — o veterinário não
 * pode aceitá-los por quem não está usando o sistema.
 */
class CadastrarTutorNaClinicaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * O e-mail é comparado como a conta o guarda: sem espaços nas pontas e em
     * minúsculas.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome completo do tutor.',
            'email.required' => 'Informe o e-mail: é para ele que vai o convite de ativação.',
            'email.email' => 'Falta a parte final do endereço, depois do ponto.',
        ];
    }
}
