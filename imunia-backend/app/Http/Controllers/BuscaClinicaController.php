<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\BuscarNaClinicaRequest;
use App\Services\BuscaClinicaService;
use Illuminate\Http\JsonResponse;

class BuscaClinicaController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly BuscaClinicaService $busca)
    {
    }

    /**
     * V03 — buscar animal ou tutor (RF51, RF18, RF13).
     *
     * O âmbito é o mesmo de V01 e V02 (RN48), mas esta tela tem uma segunda
     * resposta que aquelas não têm: a existência de cadastro fora do âmbito.
     * É o ponto de maior risco de vazamento por desenho de interface de todo o
     * sistema, e por isso o que sai daqui é o mínimo — a existência, e nada
     * dela (RN12). Quem monta esse mínimo é o serviço, que também registra a
     * consulta em log antes de revelá-la (RF18b, RF52b).
     */
    public function index(BuscarNaClinicaRequest $request): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoClinico($request);

        return response()->json([
            ...$this->busca->buscar($usuario, $prestador, $request->termo()),
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'vinculos' => $this->vinculosDe($usuario),
        ]);
    }
}
