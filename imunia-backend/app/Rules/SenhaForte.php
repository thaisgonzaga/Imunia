<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Política de senha de RN04 (extensão mínima e composição), na mesma
 * granularidade exibida ao usuário como checklist em tempo real (P03).
 */
class SenhaForte implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $senha = (string) $value;

        if (mb_strlen($senha) < 10) {
            $fail('A senha precisa ter ao menos 10 caracteres.');

            return;
        }

        if (! preg_match('/\p{Lu}/u', $senha)) {
            $fail('A senha precisa de ao menos uma letra maiúscula.');

            return;
        }

        if (! preg_match('/[0-9]|[^\p{L}\p{N}]/u', $senha)) {
            $fail('A senha precisa de ao menos um número ou símbolo.');
        }
    }
}
