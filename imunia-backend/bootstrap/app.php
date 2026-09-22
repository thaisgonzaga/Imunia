<?php

use App\Http\Middleware\IdentificarOcorrencia;
use App\Support\IdentificadorDeOcorrencia;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum em modo SPA: a sessão trafega em cookie httpOnly e o token CSRF
        // é exigido em toda requisição de escrita (RNF08).
        $middleware->statefulApi();

        // Primeiro da fila: o identificador precisa existir antes de qualquer
        // camada que possa falhar, inclusive a própria autenticação.
        $middleware->api(prepend: [IdentificarOcorrencia::class]);

        // Este Laravel não serve tela nenhuma: a entrada é do SPA (P02), e a
        // rota `login` não existe nem vai existir. Sem esta linha, requisição
        // sem sessão que não anuncie querer JSON faz o middleware de
        // autenticação montar um destino inexistente, e a falta de sessão —
        // que é 401 — chega ao cliente como falha do sistema.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /**
         * E03 — falha do sistema. A resposta que o SPA recebe quando nada mais
         * se pode fazer: uma frase em linguagem de gente (RNF17) e o número de
         * protocolo da requisição, que é o único elo entre a tela e o registro
         * do servidor.
         *
         * A decisão é tomada sobre o status já preparado pelo framework, e não
         * sobre a classe da exceção: assim tudo o que o Laravel converte em 403,
         * 404, 422 ou 429 segue com a mensagem própria daquele ponto do sistema,
         * e só o que sobra — a falha genuinamente inesperada — vira E03.
         */
        $exceptions->respond(function (Response $resposta, Throwable $excecao, Request $requisicao) {
            if ($resposta->getStatusCode() !== 500) {
                return $resposta;
            }

            if (! $requisicao->is('api/*') && ! $requisicao->expectsJson()) {
                return $resposta;
            }

            $corpo = [
                'message' => 'Não conseguimos concluir a operação. Tente novamente em instantes.',
                'ocorrencia' => $requisicao->attributes->get(
                    IdentificarOcorrencia::ATRIBUTO,
                    // A rota inexistente e o erro anterior ao roteamento não
                    // passam pelo middleware da API, e mesmo assim precisam de
                    // um número a informar.
                    IdentificadorDeOcorrencia::gerar(),
                ),
            ];

            // Fora de produção, o detalhe técnico continua na resposta: a tela
            // de exceção existe para o usuário, não para esconder do
            // desenvolvedor o que acabou de quebrar.
            if (config('app.debug')) {
                $corpo['depuracao'] = [
                    'excecao' => $excecao::class,
                    'mensagem' => $excecao->getMessage(),
                    'arquivo' => $excecao->getFile(),
                    'linha' => $excecao->getLine(),
                ];
            }

            return response()->json($corpo, 500);
        });
    })->create();
