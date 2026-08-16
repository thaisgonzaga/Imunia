<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ReenviarConfirmacaoRequest extends FormRequest
{
    /**
     * Intervalo mínimo entre reenvios, em segundos — o mesmo que a tela exibe
     * na contagem do botão "Reenviar" (P08).
     */
    private const INTERVALO = 60;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }

    protected function passedValidation(): void
    {
        $chave = 'confirmar-email:'.Str::lower($this->string('email')->toString()).'|'.$this->ip();

        if (RateLimiter::tooManyAttempts($chave, 1)) {
            abort(response()->json([
                'message' => 'A mensagem anterior saiu há pouco. Aguarde para pedir outra.',
                'segundos_restantes' => RateLimiter::availableIn($chave),
            ], 429));
        }

        RateLimiter::hit($chave, self::INTERVALO);
    }
}
