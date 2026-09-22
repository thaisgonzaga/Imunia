<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\HistoricoDeNotificacoesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T17 — histórico de notificações (RF45), pelo lado do tutor.
 *
 * Somente leitura, como o briefing manda: o registro é a fonte de verdade da
 * idempotência de RN43, e um histórico que o tutor pudesse editar deixaria de
 * responder se o lembrete já foi enviado.
 *
 * A visão do veterinário (RF45a) é outra rota e outra fatia. O âmbito dela é a
 * autorização vigente do prestador ativo, e não a titularidade — o mesmo
 * motivo por que a exportação pela clínica não é um `if` a mais em T15.
 */
class HistoricoDeNotificacoesController extends Controller
{
    public function __construct(private readonly HistoricoDeNotificacoesService $historico) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        return response()->json($this->historico->listar($tutor));
    }
}
