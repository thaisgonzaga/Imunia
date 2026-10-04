<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaCaracterizacaoDoAnimal;
use App\Models\Tutor;
use App\Rules\CpfValido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * V05 — cadastrar animal no atendimento (RF16, RF19, RF20a).
 *
 * A metade da identificação repete as regras de T03, porque o dado é o mesmo
 * seja quem for que o declare; a da caracterização vem do trait, porque aqui o
 * autor é o veterinário e os campos privativos deixam de ser proibidos para
 * serem dele. O tutor entra pelo e-mail — a chave com que o veterinário o
 * cadastra — ou pelo CPF, para quem o tiver no cadastro; nunca por
 * identificador interno.
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

        $email = trim((string) $this->input('email'));
        $cpf = preg_replace('/\D/', '', (string) $this->input('cpf'));

        $this->merge([
            'email' => $email === '' ? null : mb_strtolower($email),
            'cpf' => $cpf === '' ? null : $cpf,
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
            'email' => ['nullable', 'required_without:cpf', 'string', 'email', 'max:255'],

            // RF13 — o dígito verificador barra a consulta antes dela: um CPF
            // digitado errado não pode vincular o animal ao cadastro de outra
            // pessoa.
            'cpf' => ['nullable', new CpfValido],

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
            'email.required_without' => 'Informe o e-mail do tutor.',
            'email.email' => 'Falta a parte final do endereço, depois do ponto.',
            'nome.required' => 'Informe o nome do animal.',
            'especie.required' => 'Escolha se é um cão ou um gato.',
            'especie.in' => 'O Imunia atende cães e gatos.',

            ...$this->mensagensDeCaracterizacao(),
        ];
    }

    /**
     * O e-mail, quando veio, decide: é a chave do cadastro feito no balcão.
     */
    public function tutor(): ?Tutor
    {
        $email = $this->validated('email');

        return $email !== null
            ? Tutor::query()->doEmail($email)->first()
            : Tutor::query()->where('cpf', $this->validated('cpf'))->first();
    }

    /** O campo em que a falta de cadastro é apontada — o que foi digitado. */
    public function campoDoTutor(): string
    {
        return $this->validated('email') !== null ? 'email' : 'cpf';
    }
}
