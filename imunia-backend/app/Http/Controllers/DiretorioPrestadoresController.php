<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarDiretorioRequest;
use App\Models\User;
use App\Services\DiretorioPrestadoresService;
use Illuminate\Http\JsonResponse;

class DiretorioPrestadoresController extends Controller
{
    public function __construct(private readonly DiretorioPrestadoresService $diretorio)
    {
    }

    /**
     * T10 — diretório de prestadores (RF11).
     *
     * O espelho da busca do ambiente clínico (V03): lá o prestador procura um
     * animal e recebe quase nada; aqui o tutor procura um estabelecimento e
     * recebe o que é público a respeito dele — nome, tipo, município e contato
     * (RF11a). Nos dois casos o limite é o mesmo princípio: estar cadastrado na
     * plataforma não expõe o que se faz nela.
     *
     * A rota é do ambiente do tutor, e não aberta, porque é dela que parte a
     * concessão de autorização (RF11b): quem consulta o diretório está a um
     * passo de conceder, e o passo seguinte precisa saber quem é.
     */
    public function index(ConsultarDiretorioRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        return response()->json($this->diretorio->consultar($tutor, $request->filtro()));
    }
}
