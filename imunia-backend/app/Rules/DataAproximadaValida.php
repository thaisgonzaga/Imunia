<?php

namespace App\Rules;

use App\Support\DataAproximada;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * RF29, RN25 — a data do histórico pregresso, no formato que T09 pede ao
 * tutor: `aaaa` ou `mm/aaaa`. A mensagem repete o formato aceito em vez de
 * dizer apenas "data inválida", porque quem escreveu "junho de 2024" precisa
 * saber o que escrever no lugar.
 */
class DataAproximadaValida implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valor = trim((string) $value);

        if (! DataAproximada::reconhece($valor)) {
            $fail('Escreva o ano (2024) ou o mês e o ano (06/2024).');

            return;
        }

        if (DataAproximada::noFuturo($valor)) {
            $fail('A data precisa ser passada: o histórico pregresso registra o que já aconteceu.');
        }
    }
}
