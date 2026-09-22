<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\HistoricoConsolidadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoricoConsolidadoController extends Controller
{
    public function __construct(private readonly HistoricoConsolidadoService $historico)
    {
    }

    /**
     * T07 — histórico consolidado (RF35). Mesma checagem de âmbito de T04, T05
     * e T06: a busca parte sempre do tutor autenticado, e um código que existe
     * mas pertence a outro tutor responde 404 igual a um código inexistente
     * (RN12).
     *
     * Devolve a linha do tempo inteira, com os filtros já contados, em uma
     * requisição só: RF35 pede que os filtros apliquem sem recarregar a página,
     * e é o cliente que os aplica.
     *
     * RF52 não incide aqui. O registro de acesso em log imutável é do prestador
     * que consulta histórico produzido por outro (RN49), e esta rota é o tutor
     * vendo o próprio animal — para quem RN49 nada tem a registrar. A visão do
     * veterinário, com o aviso de acesso registrado e o estado "sem
     * autorização", é a fatia de V06 e V10.
     */
    public function show(Request $request, string $codigo): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        $animal = $tutor->animais()->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        return response()->json([
            'animal' => $animal->paraListagem(),
            ...$this->historico->montar($animal),
        ]);
    }
}
