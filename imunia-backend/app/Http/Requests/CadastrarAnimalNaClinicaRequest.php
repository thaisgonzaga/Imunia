<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaCaracterizacaoDoAnimal;
use App\Rules\CpfValido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * V05 — cadastrar animal no atendimento (RF16, RF19, RF20a).
 *
 * A metade da identificação repete as regras de T03, porque o dado é o mesmo
 * seja quem for que o declare; a da caracterização vem do trait, porque aqui o
 * autor é o veterinário e os campos privativos deixam de ser proibidos para
 * serem dele. O tutor entra por CPF — a chave que o profissional tem no balcão
 * —, nunca por identificador interno.
 */
class CadastrarAnimalNaClinicaRequest extends FormRequest
{
    use ValidaCaracterizacaoDoAnimal;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanearCaracterizacao();

        $this->merge([
            'cpf' => preg_replace('/\D/', '', (string) $this->input('cpf')),
            'nome' => is_string($this->input('nome')) && trim($this->input('nome')) === ''
                ? null
                : $this->input('nome'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // RF13 — o dígito verificador barra a consulta antes dela: um CPF
            // digitado errado não pode vincular o animal ao cadastro de outra
            // pessoa.
            'cpf' => ['required', new CpfValido],

            'nome' => ['required', 'string', 'max:60'],

            // RN13 — cão e gato, e nada além.
            'especie' => ['required', Rule::in(['cao', 'gato'])],

            // RF20a — o segundo envio, já ciente do cadastro parecido.
            'confirmar_duplicidade' => ['sometimes', 'boolean'],

            ...$this->regrasDeCaracterizacao(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cpf.required' => 'Informe o CPF do tutor.',
            'nome.required' => 'Informe o nome do animal.',
            'especie.required' => 'Escolha se é um cão ou um gato.',
            'especie.in' => 'O Imunia atende cães e gatos.',

            ...$this->mensagensDeCaracterizacao(),
        ];
    }

    public function cpfDoTutor(): string
    {
        return (string) $this->validated('cpf');
    }
}
