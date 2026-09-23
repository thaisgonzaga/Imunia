<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\StoreTutorRequest;
use App\Models\Tutor;
use App\Models\User;
use App\Rules\CpfValido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Criar o cadastro de tutor de uma conta que já existe (RF12, RN05) — a
 * terceira origem do cadastro, sem código de tela no briefing, que se soma às
 * duas de RF12: o autocadastro de P03 e o cadastro pelo prestador de V04.
 *
 * O caminho de quem já entra no Imunia por outro papel: o veterinário que
 * também tem um cão em casa não cria segunda conta nem inventa um segundo
 * endereço de correio — acrescenta o cadastro de tutor ao que já é seu.
 *
 * Por isso o formulário é curto: endereço e senha já existem, e o nome vem da
 * conta. O que falta é o que só o tutor tem — o CPF, que é único na plataforma
 * (RF12a) — e o aceite dos termos, que ninguém pratica por outro.
 */
class CriarCadastroDeTutorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza o CPF para dígitos puros antes da validação, como no
     * autocadastro de P03: a regra de dígito verificador e a consulta de
     * unicidade precisam bater com o valor salvo.
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
            'cpf' => ['required', 'string', new CpfValido],
            // Só de quem ainda não tem nome na conta — o veterinário que foi
            // convidado e nunca chegou a aceitar, por exemplo. Quem já tem
            // continua com o dele: o nome carimba a carteira exportada tanto
            // quanto os registros clínicos que assinou, e deixar dois nomes
            // divergirem faria a mesma pessoa aparecer diferente conforme a
            // tela — é o mesmo cuidado de `ContaController::update()`.
            'nome' => [blank($this->user()?->name) ? 'required' : 'prohibited', 'string', 'max:255'],
            'aceite_termos' => ['accepted'],
        ];
    }

    /**
     * A recusa por CPF já cadastrado sai pela mesma chave neutra do
     * autocadastro (`StoreTutorRequest::CAMPO_CONTA_EXISTENTE`) e com a mesma
     * frase, e não como erro do campo `cpf`.
     *
     * Aqui quem pergunta está autenticado, o que poderia sugerir folga — mas é
     * justamente o contrário: bastaria uma conta qualquer para transformar esta
     * rota em consulta de "este CPF tem cadastro no Imunia?", que é a pergunta
     * que RN12 e RF12b se recusam a responder. Fora do atendimento, onde V03
     * responde e registra em log quem perguntou (RF18b), ninguém responde.
     */
    public function after(): array
    {
        return [
            function (Validator $validador) {
                if ($validador->errors()->has('cpf')) {
                    return;
                }

                if (Tutor::query()->where('cpf', $this->input('cpf'))->exists()) {
                    $validador->errors()->add(
                        StoreTutorRequest::CAMPO_CONTA_EXISTENTE,
                        'Já existe um cadastro de tutor com estes dados.',
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'cpf.required' => 'Informe o CPF.',
            'nome.required' => 'Informe o nome completo.',
            'aceite_termos.accepted' => 'É preciso aceitar os termos de uso e a política de privacidade para criar o cadastro de tutor.',
        ];
    }

    public function usuario(): User
    {
        /** @var User $usuario */
        $usuario = $this->user();

        return $usuario;
    }
}
