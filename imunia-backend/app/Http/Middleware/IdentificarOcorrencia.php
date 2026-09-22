<?php

namespace App\Http\Middleware;

use App\Support\IdentificadorDeOcorrencia;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carimba cada requisição da API com um identificador de ocorrência e o injeta
 * no contexto de registro.
 *
 * O identificador é gerado na entrada, e não no momento da falha, porque é
 * assim que ele passa a acompanhar toda linha escrita durante a requisição — a
 * consulta lenta, o aviso do serviço, a exceção. Gerá-lo só quando algo quebra
 * daria ao usuário um número que aparece numa única linha do registro, e o
 * suporte não teria como reconstruir o que veio antes.
 */
class IdentificarOcorrencia
{
    /**
     * Chave usada para recuperar o identificador no tratador de exceções.
     */
    public const ATRIBUTO = 'ocorrencia';

    public function handle(Request $requisicao, Closure $next): Response
    {
        $ocorrencia = IdentificadorDeOcorrencia::gerar();

        $requisicao->attributes->set(self::ATRIBUTO, $ocorrencia);
        Log::withContext([self::ATRIBUTO => $ocorrencia]);

        return $next($requisicao);
    }
}
