<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAdministrado;
use App\Models\Prestador;
use App\Rules\CnpjValido;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A atualização do cadastro do prestador (A02, RF08).
 *
 * Difere de `StorePrestadorRequest` em um ponto de fundo: aqui o responsável
 * técnico é opcional. RF07c prevê o estabelecimento sem responsável técnico —
 * é o que acontece quando quem respondia pela clínica sai antes de haver
 * substituto — e trata a consequência disso, que é não poder registrar
 * informação clínica. O cadastro de P04 continua exigindo os três campos: quem
 * abre a conta declara quem responde por ela; o que muda é o que pode acontecer
 * depois.
 */
class AtualizarPrestadorRequest extends FormRequest
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

    /**
     * CNPJ e CEP chegam mascarados da tela e são guardados em dígitos puros,
     * para que a regra de dígito verificador e a unicidade batam com o valor
     * salvo — mesma normalização de `StorePrestadorRequest`.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cnpj' => preg_replace('/\D/', '', (string) $this->input('cnpj')),
            'cep' => $this->apenasDigitosOuNulo($this->input('cep')),
        ]);
    }

    public function rules(): array
    {
        $ufs = 'in:'.implode(',', StorePrestadorRequest::UFS);

        return [
            'tipo' => ['required', 'in:clinica,hospital,autonomo'],
            'nome' => ['required', 'string', 'max:255'],
            'cnpj' => [
                'required',
                'string',
                new CnpjValido,
                Rule::unique('prestadores', 'cnpj')->ignore($this->prestador()->id),
            ],
            'telefone' => ['required', 'string', 'max:20'],
            'endereco' => ['required', 'string', 'max:255'],
            'municipio' => ['required', 'string', 'max:120'],
            'uf' => ['required', 'string', $ufs],
            'cep' => ['nullable', 'string', 'digits:8'],
            'responsavel_tecnico_nome' => ['nullable', 'string', 'max:255'],
            'responsavel_tecnico_crmv' => ['nullable', 'string', 'max:20', 'regex:/^\d+$/'],
            'responsavel_tecnico_crmv_uf' => ['nullable', 'string', $ufs],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.unique' => 'Já existe um estabelecimento cadastrado com este CNPJ.',
            'cep.digits' => 'O CEP tem 8 dígitos.',
            'responsavel_tecnico_crmv.regex' => 'Informe apenas o número da inscrição, sem “CRMV” e sem a UF.',
        ];
    }

    /**
     * O responsável técnico é uma unidade: ou vêm os três campos, ou nenhum.
     * Meio responsável técnico não identifica ninguém perante o conselho, e um
     * CRMV sem UF não é um registro (RN09).
     */
    public function after(): array
    {
        return [
            function (Validator $validador) {
                $campos = [
                    'responsavel_tecnico_nome',
                    'responsavel_tecnico_crmv',
                    'responsavel_tecnico_crmv_uf',
                ];

                $preenchidos = array_filter(
                    $campos,
                    fn (string $campo) => filled($this->input($campo)),
                );

                if ($preenchidos === [] || count($preenchidos) === count($campos)) {
                    return;
                }

                $validador->errors()->add(
                    'responsavel_tecnico_nome',
                    'Informe nome, CRMV e UF do responsável técnico, ou deixe os três em branco.',
                );
            },
        ];
    }

    private function apenasDigitosOuNulo(mixed $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor);

        return $digitos === '' ? null : $digitos;
    }
}
