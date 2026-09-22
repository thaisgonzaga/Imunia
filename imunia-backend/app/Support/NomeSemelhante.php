<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * RF20a — "nome semelhante", que é o que separa o segundo cadastro de Théo de
 * um irmão chamado Téo.
 *
 * A comparação vive aqui, e não na consulta, porque semelhança não é coisa que
 * o banco saiba responder: `like` acha "Theo" dentro de "Theodoro" e não acha
 * "Téo" ao lado de "Theo", que é justamente o caso que RF20a quer pegar — o
 * mesmo animal cadastrado duas vezes por quem digitou o acento de um jeito na
 * primeira vez e de outro na segunda.
 *
 * O alerta que isto alimenta nunca impede o cadastro (o tutor pode ter dois
 * gatos de nomes parecidos, e ninguém precisa provar o contrário): um falso
 * positivo custa um clique a mais, e é por isso que o limiar pode ser generoso.
 */
final class NomeSemelhante
{
    /**
     * Percentual de `similar_text` a partir do qual dois nomes valem alerta.
     * Em 80: "Theo" e "Teo" alertam (85%); "Nina" e "Nino" não (75%), porque
     * uma letra no fim de nome curto costuma ser outro animal, não outro
     * cadastro do mesmo.
     */
    private const LIMIAR = 80;

    public static function entre(string $um, string $outro): bool
    {
        $primeiro = self::normalizar($um);
        $segundo = self::normalizar($outro);

        if ($primeiro === '' || $segundo === '') {
            return false;
        }

        if ($primeiro === $segundo) {
            return true;
        }

        similar_text($primeiro, $segundo, $percentual);

        return $percentual >= self::LIMIAR;
    }

    /**
     * "Théo", "THEO" e "  theo " são o mesmo nome para efeito de duplicidade.
     * A acentuação some porque é a diferença que mais se produz sozinha entre
     * dois cadastros do mesmo animal, e não a que distingue dois animais.
     */
    private static function normalizar(string $nome): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($nome)));
    }
}
