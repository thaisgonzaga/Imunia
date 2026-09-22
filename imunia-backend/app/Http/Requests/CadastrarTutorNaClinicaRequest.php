<?php

namespace App\Http\Requests;

use App\Rules\CpfValido;
use Illuminate\Foundation\Http\FormRequest;

/**
 * V04 — cadastrar tutor no atendimento (RF12).
 *
 * Só a forma do que foi digitado é conferida aqui. A existência de cadastro —
 * CPF que já é de alguém, e-mail que já tem conta — é decidida no controlador,
 * porque não é defeito do que se digitou: é estado da plataforma, tem ordem de
 * precedência própria (o CPF existente conduz ao fluxo de RF13, e o e-mail nem
 * chega a importar nesse caso) e, no caso do CPF, gera registro de acesso.
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
     * Normaliza o CPF para dígitos puros antes da validação, para que a regra
     * de dígito verificador e a consulta de existência batam com o valor salvo.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf' => preg_replace('/\D/', '', (string) $this->input('cpf')),
        ]);
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'cpf' => ['required', 'string', new CpfValido],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome completo do tutor.',
            'cpf.required' => 'Informe o CPF.',
            'email.required' => 'Informe o e-mail: é para ele que vai o convite de ativação.',
            'email.email' => 'Falta a parte final do endereço, depois do ponto.',
        ];
    }
}
