<?php

namespace App\Http\Requests;

use App\Rules\DocumentoInscricaoValido;
use App\Rules\SenhaForte;
use Illuminate\Foundation\Http\FormRequest;

class StoreTutorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza o CPF para dígitos puros antes da validação, para que a
     * regra de dígito verificador e a unicidade batam com o valor salvo.
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
            'cpf' => ['required', 'string', new DocumentoInscricaoValido(apenasCpf: true), 'unique:tutores,cpf'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', new SenhaForte],
            // Desmarcar a caixa na interface é conveniência; a recusa aqui é a
            // garantia — sem o aceite não existe conta.
            'aceite_termos' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'cpf.unique' => 'Já existe uma conta com este CPF.',
            'email.unique' => 'Este e-mail já está em uso.',
            'aceite_termos.accepted' => 'É preciso aceitar os termos de uso e o aviso de privacidade para criar a conta.',
        ];
    }
}
