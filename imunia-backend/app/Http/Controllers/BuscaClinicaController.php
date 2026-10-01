<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\BuscarNaClinicaRequest;
use App\Services\BuscaClinicaService;
use Illuminate\Http\JsonResponse;

class BuscaClinicaController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly BuscaClinicaService $busca) {}

    /**
     * V03 — buscar animal ou tutor (RF51, RF18, RF13).
     *
     * Por nome, o âmbito é o mesmo de V01 e V02: a carteira do prestador ativo
     * (RN48). Por CPF, código ou micro-chip, a busca alcança qualquer animal,
     * porque quem tem o identificador na mão está com o animal à sua frente. O
     * encontro de animal fora da carteira é registrado em log antes de a
     * resposta sair (RF18b, RF52b), e o tutor o vê em T14 — quem decide o
     * alcance e grava a linha é o serviço.
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
