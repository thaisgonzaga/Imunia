<?php

namespace App\Http\Requests;

use App\Rules\CnpjValido;
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
     * Normaliza o CNPJ para dígitos puros antes da validação, para que a regra
     * de dígito verificador e a unicidade batam com o valor salvo.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cnpj' => preg_replace('/\D/', '', (string) $this->input('cnpj')),
        ]);
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:clinica,hospital,autonomo'],
            'nome' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', new CnpjValido, 'unique:prestadores,cnpj'],
            'telefone' => ['required', 'string', 'max:20'],
            'endereco' => ['required', 'string', 'max:255'],
            'municipio' => ['required', 'string', 'max:120'],
            'uf' => ['required', 'string', 'in:'.implode(',', self::UFS)],
            'responsavel_tecnico_nome' => ['required', 'string', 'max:255'],
            'responsavel_tecnico_crmv' => ['required', 'string', 'max:20', 'regex:/^\d+$/'],
            'responsavel_tecnico_crmv_uf' => ['required', 'string', 'in:'.implode(',', self::UFS)],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', new SenhaForte],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.unique' => 'Já existe um estabelecimento cadastrado com este CNPJ.',
            'responsavel_tecnico_crmv.regex' => 'Informe apenas o número da inscrição, sem “CRMV” e sem a UF.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
        ];
    }
}
