<?php

namespace App\Support;

use App\Models\Animal;

/**
 * Gerador do código único do animal (RF17).
 */
class CodigoDoAnimal
{
    /**
     * Alfabeto sem os caracteres que se confundem na leitura em voz alta e na
     * transcrição a partir do papel: 0 e O, 1 e I. O código é ditado ao balcão
     * da clínica e digitado por quem não o está vendo.
     */
    private const ALFABETO = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const PREFIXO = 'IM';

    private const TAMANHO_DO_BLOCO = 4;

    /**
     * RN15 exige identificador não sequencial: nada aqui deriva da chave
     * primária, da data ou de contador algum, de modo que conhecer um código
     * não permite adivinhar outro. A colisão entre dois sorteios é improvável
     * — 32⁸ combinações —, mas não impossível, e por isso é conferida.
     */
    public static function gerar(): string
    {
        do {
            $codigo = sprintf('%s-%s-%s', self::PREFIXO, self::bloco(), self::bloco());
        } while (Animal::where('codigo', $codigo)->exists());

        return $codigo;
    }

    private static function bloco(): string
    {
        $bloco = '';
        $ultimo = strlen(self::ALFABETO) - 1;

        for ($i = 0; $i < self::TAMANHO_DO_BLOCO; $i++) {
            $bloco .= self::ALFABETO[random_int(0, $ultimo)];
        }

        return $bloco;
    }
}
