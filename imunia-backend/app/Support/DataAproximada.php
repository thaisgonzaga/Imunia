<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A data de um registro pregresso, como o tutor consegue dizê-la (RF29, RN25):
 * "2024", ou "05/2024" quando lembra o mês. Nunca o dia — o campo se chama
 * "data aproximada" na tela de T09, e aceitar `dd/mm/aaaa` aqui daria ao
 * registro uma precisão que o próprio rótulo nega.
 *
 * O armazenamento continua sendo uma data completa, porque a coluna é `date` e
 * o cálculo do calendário precisa comparar datas. A imprecisão não se perde: a
 * viagem de volta é `data_aproximada = true`, e é essa marca — não o dia
 * gravado — que decide como a data é exibida (`dataDeRegistro`, no cliente).
 */
class DataAproximada
{
    /**
     * `aaaa` ou `mm/aaaa`, com o mês em um ou dois dígitos.
     */
    private const PADRAO = '/^(?:(0?[1-9]|1[0-2])\/)?((?:19|20)\d{2})$/';

    public static function reconhece(string $valor): bool
    {
        return preg_match(self::PADRAO, trim($valor)) === 1;
    }

    /**
     * O primeiro dia do período informado — o menor instante que o tutor
     * afirmou. Arredondar para o meio do mês ou do ano seria inventar
     * precisão; escolher o início é a única leitura que não acrescenta nada.
     */
    public static function interpretar(string $valor): ?CarbonImmutable
    {
        if (preg_match(self::PADRAO, trim($valor), $partes) !== 1) {
            return null;
        }

        $mes = $partes[1] === '' ? 1 : (int) $partes[1];

        return CarbonImmutable::create((int) $partes[2], $mes, 1, 0, 0, 0);
    }

    /**
     * Uma aplicação que ainda não aconteceu não é histórico. O limite é o fim
     * do mês corrente, e não hoje, porque quem escreve "08/2026" no dia 4 está
     * dizendo "neste mês", não "no primeiro dia dele".
     */
    public static function noFuturo(string $valor): bool
    {
        $data = self::interpretar($valor);

        return $data !== null && $data->gt(CarbonImmutable::today()->endOfMonth());
    }
}
