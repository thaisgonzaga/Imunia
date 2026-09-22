<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\BuscarNaClinicaRequest;
use App\Services\EscolhaDeAnimalService;
use Illuminate\Http\JsonResponse;

class EscolhaDeAnimalController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly EscolhaDeAnimalService $escolha) {}

    /**
     * V07a e V08a — escolher o animal antes de registrar.
     *
     * Rota própria, e não `?registrar=` sobre a busca de V03, porque a resposta
     * é outra: além do resultado da consulta, esta tela precisa do atalho dos
     * últimos atendidos, que V03 não tem e não deveria ter — lá o campo vazio é
     * o estado em que nada foi consultado, e uma lista de animais ali diria ao
     * profissional coisas que ele não perguntou.
     *
     * O pedido de termo é o mesmo, com a mesma validação: o CPF de dígito
     * incorreto é erro de campo aqui como lá, porque a promessa de que nada foi
     * consultado nem registrado vale nas duas telas.
     */
    public function index(BuscarNaClinicaRequest $request): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoClinico($request);

        return response()->json([
            ...$this->escolha->montar($usuario, $prestador, $request->termo()),
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'vinculos' => $this->vinculosDe($usuario),
        ]);
    }
}
