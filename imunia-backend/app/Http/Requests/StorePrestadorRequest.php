<?php

namespace App\Http\Requests;

use App\Rules\DocumentoInscricaoValido;
use App\Rules\SenhaForte;
use Illuminate\Foundation\Http\FormRequest;

class StorePrestadorRequest extends FormRequest
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
        'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza o documento para dígitos puros antes da validação, para que
     * a regra de dígito verificador e a unicidade batam com o valor salvo.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'documento' => preg_replace('/\D/', '', (string) $this->input('documento')),
        ]);
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:clinica,hospital,autonomo'],
            'nome' => ['required', 'string', 'max:255'],
            'documento' => ['required', 'string', new DocumentoInscricaoValido, 'unique:prestadores,documento'],
            'telefone' => ['required', 'string', 'max:20'],
            'endereco' => ['required', 'string', 'max:255'],
            'municipio' => ['required', 'string', 'max:120'],
            'uf' => ['required', 'string', 'in:'.implode(',', self::UFS)],
            'responsavel_tecnico_nome' => ['required', 'string', 'max:255'],
            'responsavel_tecnico_crmv' => ['required', 'string', 'max:20'],
            'responsavel_tecnico_crmv_uf' => ['required', 'string', 'in:'.implode(',', self::UFS)],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', new SenhaForte],
        ];
    }

    public function messages(): array
    {
        return [
            'documento.unique' => 'Já existe um estabelecimento cadastrado com este documento.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
        ];
    }
}
