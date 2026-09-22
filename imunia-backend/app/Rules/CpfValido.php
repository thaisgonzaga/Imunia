<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida o CPF (11 dígitos) pelo algoritmo padrão de dígitos verificadores. O
 * valor já chega aqui normalizado (somente dígitos) pelo prepareForValidation()
 * do FormRequest chamador.
 *
 * É a regra do tutor, entidade sempre pessoa física (RF12). O prestador tem a
 * sua própria (`CnpjValido`), e as duas são separadas de propósito: nenhum
 * cadastro do Imunia aceita os dois documentos, então não há o que decidir em
 * tempo de validação.
 */
class CpfValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digitos = preg_replace('/\D/', '', (string) $value);

        if (strlen($digitos) !== 11) {
            $fail('Informe um CPF (11 dígitos) válido.');

            return;
        }

        if (! $this->digitosVerificadoresConferem($digitos)) {
            $fail('O CPF informado é inválido.');
        }
    }

    private function digitosVerificadoresConferem(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($posicaoDigito = 9; $posicaoDigito <= 10; $posicaoDigito++) {
            $soma = 0;
            for ($i = 0; $i < $posicaoDigito; $i++) {
                $soma += (int) $cpf[$i] * (($posicaoDigito + 1) - $i);
            }
            $resto = ($soma * 10) % 11;
            $digitoEsperado = $resto === 10 ? 0 : $resto;

            if ((int) $cpf[$posicaoDigito] !== $digitoEsperado) {
                return false;
            }
        }

        return true;
    }
}
