<?php

namespace App\Http\Controllers;

use App\Services\LembretesAoTutorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O disparo externo da rotina de lembretes (RF42, RF43). Existe por causa do
 * plano gratuito do Render, que hiberna sem acesso e não oferece cron: uma
 * tarefa agendada no GitHub Actions chama esta rota uma vez por dia, e a
 * chamada acorda o servidor e executa a mesma rotina do comando
 * `imunia:lembretes`.
 *
 * Não há sessão nem usuário: a prova é o segredo de `LEMBRETES_TOKEN`. Sem ele
 * configurado, ou com o segredo errado, a resposta é 404 — a rota não anuncia
 * que existe a quem não sabe que ela existe.
 */
class RotinaDeLembretesController extends Controller
{
    public function __invoke(Request $request, LembretesAoTutorService $lembretes): JsonResponse
    {
        $segredo = (string) config('app.lembretes_token');

        abort_if($segredo === '' || ! hash_equals($segredo, (string) $request->bearerToken()), 404);

        return response()->json($lembretes->enviar());
    }
}
