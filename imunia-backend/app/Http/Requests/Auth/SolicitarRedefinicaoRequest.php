<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SolicitarRedefinicaoRequest extends FormRequest
{
    /**
     * Pedidos tolerados antes da pausa (RNF11).
     */
    private const PEDIDOS = 3;

    /**
     * Duração da pausa, em segundos. A tela exibe a contagem regressiva (P05).
     */
    private const PAUSA = 10 * 60;

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
        $chave = 'recuperar-senha:'.Str::lower($this->string('email')->toString()).'|'.$this->ip();

        if (RateLimiter::tooManyAttempts($chave, self::PEDIDOS)) {
            abort(response()->json([
                'message' => 'Já recebemos vários pedidos de recuperação. Espere alguns minutos antes de pedir outro.',
                'segundos_restantes' => RateLimiter::availableIn($chave),
            ], 429));
        }

        RateLimiter::hit($chave, self::PAUSA);
    }
}
