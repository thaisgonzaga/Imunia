<?php

namespace App\Support;

class Enumeracao
{
    /**
     * Enumera nomes como se escreve em português: "Théo", "Théo e Nina",
     * "Théo, Nina e Bidu".
     *
     * Existe porque as mensagens desta família falam de um conjunto que o tutor
     * escolheu item a item (RF36a), e "Théo, Nina" com vírgula no fim soa a
     * lista de sistema — justamente o tom que o texto de consentimento não pode
     * ter. O mesmo vocabulário está em `lib/animais.js`, para as telas.
     *
     * @param  list<string>  $nomes
     */
    public static function emPortugues(array $nomes): string
    {
        if ($nomes === []) {
            return '';
        }

        if (count($nomes) === 1) {
            return $nomes[0];
        }

        $ultimo = array_pop($nomes);

        return implode(', ', $nomes).' e '.$ultimo;
    }
}
