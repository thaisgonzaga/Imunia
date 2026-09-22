<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A validade do frasco, em mês e ano (RF25a, RN22).
 *
 * O rótulo do imunobiológico traz "04/2027", e não um dia — é assim que a
 * indústria imprime e é assim que o profissional confere, com o frasco na mão.
 * Pedir um dia obrigaria a inventá-lo, e T06 o exibiria ao tutor como se
 * alguém o tivesse lido.
 *
 * Não serve `App\Support\DataAproximada`, que resolve problema parecido para o
 * histórico pregresso: lá a imprecisão é do passado e a regra escolhe
 * deliberadamente o **primeiro** dia do período ("escolher o início é a única
 * leitura que não acrescenta nada"). Aqui a leitura correta é a oposta — um
 * lote com validade 04/2027 vale até o fim de 30/04/2027 —, e aceitar o ano
 * sozinho seria aceitar uma validade de doze meses de largura.
 */
class ValidadeMesAno implements ValidationRule
{
    /**
     * O último dia do mês informado, ou nulo se a cadeia não for uma validade.
     * É o que a FormRequest grava no lugar do texto digitado.
     */
    public static function interpretar(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', trim($valor), $partes) !== 1) {
            return null;
        }

        return Carbon::createFromDate((int) $partes[2], (int) $partes[1], 1)->endOfMonth()->startOfDay();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::interpretar($value) === null) {
            $fail('Informe a validade no formato mês/ano, como 04/2027.');
        }
    }
}
