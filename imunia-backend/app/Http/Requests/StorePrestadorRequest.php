<?php

namespace App\Http\Requests;

use App\Rules\CnpjValido;
use App\Rules\SenhaForte;
use Illuminate\Foundation\Http\FormRequest;

/**
 * P04 — cadastrar prestador (RF07).
 *
 * O formulário é um só, e pede endereço e senha de quem quer que o envie. A
 * sessão de quem está no navegador não o encurta: ela diz quem preenche, e não
 * quem vai administrar o estabelecimento — são a mesma pessoa com frequência,
 * mas o cadastro não tem como saber, e supô-lo criaria vínculo e CRMV numa
 * conta que ninguém indicou.
 */
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
     * de dígito verificador e a unicidade batam com o valor salvo. Vazio vira
     * nulo, e não texto vazio: o índice único admite vários nulos, mas não
     * duas strings vazias.
     */
    protected function prepareForValidation(): void
    {
        $digitos = preg_replace('/\D/', '', (string) $this->input('cnpj'));

        $this->merge([
            'cnpj' => $digitos === '' ? null : $digitos,
        ]);
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:clinica,hospital,autonomo'],
            'nome' => ['required', 'string', 'max:255'],
            // O CNPJ identifica clínica e hospital. O profissional autônomo se
            // cadastra sem ele: o campo nem aparece na tela, e o que vier é
            // descartado — o autônomo é identificado pelo CRMV do passo 2.
            'cnpj' => ['exclude_if:tipo,autonomo', 'required', 'string', new CnpjValido, 'unique:prestadores,cnpj'],
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
            'email.unique' => 'Já existe uma conta com este e-mail. Se você atende em um estabelecimento que já usa o Imunia, o vínculo chega por convite de quem administra a conta.',
        ];
    }
}
