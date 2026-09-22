<?php

namespace App\Http\Requests\Auth;

use App\Rules\SenhaForte;
use Illuminate\Foundation\Http\FormRequest;

/**
 * T18 — troca de senha pelo próprio titular (RF04).
 *
 * A senha vigente é exigida (RF04a) e não é formalidade: sem ela, uma sessão
 * deixada aberta num computador de clínica bastaria para tomar a conta —
 * trocar a senha é justamente o que expulsa o dono dela.
 */
class AlterarSenhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'senha_atual' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', new SenhaForte],
        ];
    }

    public function messages(): array
    {
        return [
            'senha_atual.required' => 'Informe a senha que você usa hoje.',
            'senha_atual.current_password' => 'Esta não é a sua senha atual.',
            'password.required' => 'Crie a nova senha.',
            'password.confirmed' => 'As duas senhas não são iguais.',
        ];
    }
}
