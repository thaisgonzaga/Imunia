<?php

namespace App\Http\Requests;

use App\Models\ConfirmacaoDeAutorizacao;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * T11, passo 3 — o código de volta (RF37).
 *
 * A requisição carrega seis dígitos e nada mais: o que será concedido já está
 * na confirmação, guardado desde os passos anteriores. Aceitar aqui o prestador
 * ou os animais de novo abriria a porta para que o corpo desta requisição
 * discordasse do resumo que o tutor leu antes de digitar — e o consentimento
 * passaria a ser sobre outra coisa.
 */
class ConfirmarAutorizacaoRequest extends FormRequest
{
    public ?Tutor $tutor = null;

    public ConfirmacaoDeAutorizacao $confirmacao;

    public function authorize(): bool
    {
        /** @var User $usuario */
        $usuario = $this->user();
        $this->tutor = $usuario->tutor;

        abort_if($this->tutor === null, 403, 'Esta área é do ambiente do tutor.');

        /** @var ConfirmacaoDeAutorizacao $confirmacao */
        $confirmacao = $this->route('confirmacao');

        // Confirmação de outro usuário responde como confirmação inexistente:
        // saber que aquele identificador existe já seria saber demais.
        abort_if($confirmacao->user_id !== $usuario->id, 404, 'Autorização não encontrada.');

        $this->confirmacao = $confirmacao;

        return true;
    }

    protected function prepareForValidation(): void
    {
        $codigo = $this->input('codigo');

        // O tutor copia o código do e-mail e traz espaço, traço ou o que o
        // aplicativo de correio tiver inserido no meio. Recusar por causa disso
        // seria transformar um acerto em erro de digitação — e gastar uma das
        // cinco tentativas dele.
        if (is_string($codigo)) {
            $this->merge(['codigo' => preg_replace('/\D/', '', $codigo)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'digits:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'Digite o código que enviamos para o seu e-mail.',
            'codigo.digits' => 'O código tem seis números.',
        ];
    }
}
