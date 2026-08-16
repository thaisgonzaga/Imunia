<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Tentativas malsucedidas toleradas antes do bloqueio temporário (RN03).
     */
    private const TENTATIVAS = 5;

    /**
     * Duração do bloqueio, em segundos. A tela exibe o tempo restante em
     * contagem regressiva (P02).
     */
    private const BLOQUEIO = 15 * 60;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'lembrar' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Autentica e estabelece a sessão. A mensagem de falha é idêntica para
     * endereço inexistente e para senha incorreta, de modo a não revelar quais
     * endereços possuem conta na plataforma (RF01b).
     */
    public function autenticar(): void
    {
        $this->assegurarQueNaoEstaBloqueado();

        $credenciais = [
            'email' => (string) $this->input('email'),
            'password' => (string) $this->input('password'),
        ];

        if (! Auth::attempt($credenciais, $this->boolean('lembrar'))) {
            RateLimiter::hit($this->chaveDeTentativas(), self::BLOQUEIO);

            throw ValidationException::withMessages([
                'email' => 'E-mail ou senha incorretos.',
            ]);
        }

        RateLimiter::clear($this->chaveDeTentativas());

        $this->session()->regenerate();
    }

    /**
     * O bloqueio responde 429 com o tempo restante, e não 422: a tela troca de
     * estado inteira, em vez de marcar um campo como inválido.
     */
    private function assegurarQueNaoEstaBloqueado(): void
    {
        if (RateLimiter::tooManyAttempts($this->chaveDeTentativas(), self::TENTATIVAS)) {
            abort(response()->json([
                'message' => 'Foram cinco tentativas seguidas sem sucesso. A pausa protege a conta de quem tenta senhas em sequência.',
                'segundos_restantes' => RateLimiter::availableIn($this->chaveDeTentativas()),
            ], 429));
        }
    }

    /**
     * A contagem é por endereço e origem em conjunto (RN03): assim, uma origem
     * hostil não tranca a conta de um terceiro, e nem burla o limite trocando
     * de endereço.
     */
    private function chaveDeTentativas(): string
    {
        return 'login:'.Str::lower((string) $this->input('email')).'|'.$this->ip();
    }
}
