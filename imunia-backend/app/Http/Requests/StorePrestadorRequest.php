<?php

namespace App\Http\Requests;

use App\Rules\CnpjValido;
use App\Rules\SenhaForte;
use Illuminate\Foundation\Http\FormRequest;

/**
 * P04 — cadastrar prestador (RF07).
 *
 * O formulário tem duas leituras, conforme quem o envia. O visitante anônimo
 * cria conta e estabelecimento no mesmo ato, e por isso informa endereço e
 * senha. Quem já está no Imunia — a tutora que agora abre o próprio
 * consultório, o veterinário que deixa a clínica onde era convidado — cadastra
 * o estabelecimento **na conta que já tem**: endereço e senha já existem, e
 * pedi-los de novo só poderia produzir uma segunda conta para a mesma pessoa,
 * que é justamente o que RN05 dispensa.
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
     * de dígito verificador e a unicidade batam com o valor salvo.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cnpj' => preg_replace('/\D/', '', (string) $this->input('cnpj')),
        ]);
    }

    /**
     * Se o cadastro nasce junto com a conta ou dentro de uma que já existe.
     */
    public function criaConta(): bool
    {
        return $this->user() === null;
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
            // Proibidos, e não ignorados, para quem já tem sessão: o campo
            // preenchido nesse caso só pode significar que a tela mandou o que
            // não devia, e aceitá-lo em silêncio deixaria a pessoa acreditando
            // ter trocado de endereço ou de senha por aqui.
            'email' => $this->criaConta()
                ? ['required', 'string', 'email', 'max:255', 'unique:users,email']
                : ['prohibited'],
            'password' => $this->criaConta()
                ? ['required', 'confirmed', new SenhaForte]
                : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.unique' => 'Já existe um estabelecimento cadastrado com este CNPJ.',
            'responsavel_tecnico_crmv.regex' => 'Informe apenas o número da inscrição, sem “CRMV” e sem a UF.',
            'email.unique' => 'Já existe uma conta com este e-mail. Entre com ela e cadastre o estabelecimento por dentro: o Imunia não precisa de uma segunda conta para a mesma pessoa.',
            'email.prohibited' => 'Você já está em uma conta: o estabelecimento será cadastrado nela.',
            'password.prohibited' => 'Você já está em uma conta: continue com a senha que já usa.',
        ];
    }
}
