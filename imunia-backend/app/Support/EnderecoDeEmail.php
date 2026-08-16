<?php

namespace App\Support;

use Illuminate\Support\Str;

class EnderecoDeEmail
{
    /**
     * Mascara o endereço para exibição em tela pública, preservando o bastante
     * para o titular se reconhecer sem que a tela sirva de confirmação de
     * cadastro a terceiros: `helena.ramos@exemplo.com` vira `hel•••@exemplo.com`.
     */
    public static function mascarar(string $email): string
    {
        [$local, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        if ($dominio === '') {
            return str_repeat('•', 3);
        }

        $visivel = Str::substr($local, 0, min(3, max(1, Str::length($local) - 1)));

        return $visivel.'•••@'.$dominio;
    }
}
