<?php

namespace App\Support;

/**
 * Identificador da requisição, exibido ao usuário quando o sistema falha (E03).
 *
 * Não é código de erro: não classifica a falha, não diz o que quebrou e não
 * varia com o tipo de exceção. É apenas um número de protocolo — o que a pessoa
 * lê em voz alta ao suporte para que se ache, no registro do servidor, a linha
 * daquela requisição e só dela.
 */
class IdentificadorDeOcorrencia
{
    /**
     * O mesmo alfabeto do código do animal, e pela mesma razão: o identificador
     * é ditado por telefone e transcrito por quem não o está vendo. Sem 0 e O,
     * sem 1 e I.
     */
    private const ALFABETO = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const TAMANHO_DO_BLOCO = 4;

    /**
     * Dois blocos de quatro, no formato que o desenho de E03 exibe em
     * monoespaçada. Nada aqui deriva de identificador de usuário, de sessão ou
     * de qualquer dado pessoal (RNF12) — o sorteio é a única fonte.
     */
    public static function gerar(): string
    {
        return sprintf('%s-%s', self::bloco(), self::bloco());
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
