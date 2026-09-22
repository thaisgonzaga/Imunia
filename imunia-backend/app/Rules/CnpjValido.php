<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida o CNPJ (14 dígitos) pelo algoritmo padrão de dígitos verificadores. O
 * valor já chega aqui normalizado (somente dígitos) pelo prepareForValidation()
 * do FormRequest chamador.
 *
 * É a regra do prestador, e vale para os três tipos de RF07a — inclusive o
 * profissional autônomo, que também se inscreve como pessoa jurídica: o
 * médico-veterinário não pode ser microempreendedor individual, e a atividade
 * fica sob CNPJ. Por isso o cadastro não pede CPF de estabelecimento algum.
 */
class CnpjValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digitos = preg_replace('/\D/', '', (string) $value);

        if (strlen($digitos) !== 14) {
            $fail('Informe um CNPJ (14 dígitos) válido.');

            return;
        }

        if (! $this->digitosVerificadoresConferem($digitos)) {
            $fail('O CNPJ informado é inválido.');
        }
    }

    private function digitosVerificadoresConferem(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $pesosPrimeiroDigito = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesosSegundoDigito = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        foreach ([$pesosPrimeiroDigito, $pesosSegundoDigito] as $indice => $pesos) {
            $posicaoDigito = 12 + $indice;
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }
            $resto = $soma % 11;
            $digitoEsperado = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $cnpj[$posicaoDigito] !== $digitoEsperado) {
                return false;
            }
        }

        return true;
    }
}
