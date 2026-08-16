<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida CPF (11 dígitos) ou CNPJ (14 dígitos) pelo algoritmo padrão de
 * dígitos verificadores. O valor já chega aqui normalizado (somente dígitos)
 * pelo prepareForValidation() do FormRequest chamador.
 */
class DocumentoInscricaoValido implements ValidationRule
{
    /**
     * @param  bool  $apenasCpf  Quando verdadeiro, recusa CNPJ mesmo que o dígito verificador confira —
     *                           uso do tutor, entidade sempre pessoa física (RF12).
     */
    public function __construct(private bool $apenasCpf = false)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digitos = preg_replace('/\D/', '', (string) $value);

        if (strlen($digitos) === 11) {
            if (! $this->cpfValido($digitos)) {
                $fail('O CPF informado é inválido.');
            }

            return;
        }

        if (! $this->apenasCpf && strlen($digitos) === 14) {
            if (! $this->cnpjValido($digitos)) {
                $fail('O CNPJ informado é inválido.');
            }

            return;
        }

        $fail($this->apenasCpf
            ? 'Informe um CPF (11 dígitos) válido.'
            : 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.');
    }

    private function cpfValido(string $cpf): bool
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

    private function cnpjValido(string $cnpj): bool
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
