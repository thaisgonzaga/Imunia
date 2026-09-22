<?php

namespace App\Http\Requests;

use App\Models\Tutor;
use App\Models\User;
use App\Rules\CpfValido;
use App\Rules\SenhaForte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTutorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A chave por onde sai a recusa por cadastro existente. Não é o nome de
     * campo algum de propósito: ver `after()`.
     */
    public const CAMPO_CONTA_EXISTENTE = 'conta';

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
            'cpf' => ['required', 'string', new CpfValido],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', new SenhaForte],
            // Desmarcar a caixa na interface é conveniência; a recusa aqui é a
            // garantia — sem o aceite não existe conta.
            'aceite_termos' => ['accepted'],
        ];
    }

    /**
     * A duplicidade é apurada aqui, e não por `unique` em cada campo, porque a
     * chave do erro conta o que a mensagem se recusa a contar: `errors.cpf`
     * confirma que aquele CPF tem conta, `errors.email` confirma o mesmo do
     * endereço, e quem tenta adivinhar cadastros lê a resposta, não a tela.
     * Os dois casos saem pela mesma chave, com a mesma frase, e quem chega com
     * ambos em uso recebe um erro só — as três situações são indistinguíveis
     * de fora (RF12b).
     *
     * CPF malformado e e-mail malformado seguem respondendo no seu campo: aí a
     * recusa é sobre o que foi digitado, e nada afirma sobre cadastro alheio.
     */
    public function after(): array
    {
        return [
            function (Validator $validador) {
                if ($validador->errors()->hasAny(['cpf', 'email'])) {
                    return;
                }

                $emUso = Tutor::where('cpf', $this->input('cpf'))->exists()
                    || User::where('email', $this->input('email'))->exists();

                if ($emUso) {
                    $validador->errors()->add(
                        self::CAMPO_CONTA_EXISTENTE,
                        'Já existe uma conta com estes dados.',
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            // A interface mostra estas mensagens tal como vêm; sem elas, quem
            // chegasse aqui com campo vazio leria a frase padrão em inglês.
            'nome.required' => 'Informe o nome completo.',
            'cpf.required' => 'Informe o CPF.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Falta a parte final do endereço, depois do ponto.',
            'password.required' => 'Crie uma senha.',
            'password.confirmed' => 'As duas senhas não são iguais.',
            'aceite_termos.accepted' => 'É preciso aceitar os termos de uso e a política de privacidade para criar a conta.',
        ];
    }
}
