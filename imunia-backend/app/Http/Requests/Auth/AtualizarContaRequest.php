<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T18 — dados pessoais da própria conta (RF06).
 *
 * Ao contrário do autocadastro de P03, aqui a recusa por endereço em uso sai
 * pelo campo `email`, com a frase que diz o que houve. A discrição de RF12b
 * protege o formulário **público** de servir de consulta a quais endereços
 * possuem conta; esta rota exige sessão, e quem pergunta já está identificado
 * pelo cookie que a abriu. Guardar segredo aqui não esconderia o cadastro de
 * ninguém — apenas deixaria o titular olhando para um formulário que se recusa
 * a salvar sem dizer por quê.
 *
 * O CPF não figura: ele identifica o titular perante RF13 e viaja nos
 * documentos exportados. Corrigi-lo é caso de suporte, não de formulário.
 */
class AtualizarContaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome completo.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Falta a parte final do endereço, depois do ponto.',
            'email.unique' => 'Este endereço já pertence a outra conta do Imunia.',
        ];
    }
}
