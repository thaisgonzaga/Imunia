<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CalendarioVacinalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarteiraVacinacaoController extends Controller
{
    public function __construct(private readonly CalendarioVacinalService $calendario)
    {
    }

    /**
     * T05 — carteira de vacinação digital (RF28, RF26). Mesma checagem de
     * âmbito de T02 e T04: a busca parte sempre do tutor autenticado, e um
     * código que existe mas pertence a outro tutor responde 404, igual a um
     * código inexistente.
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
            ...$this->calendario->montarCarteira($animal),
        ]);
    }

    /**
     * T06 — detalhe de uma aplicação (RF25, RF30). Mesma checagem de âmbito
     * de `show()`: a vacinação também precisa pertencer ao animal encontrado,
     * ou o registro de outro animal (ou de outro tutor) responde 404 igual a
     * um id inexistente.
     */
    public function aplicacao(Request $request, string $codigo, int $vacinacao): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        $animal = $tutor->animais()->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        $registro = $animal->vacinacoes()->find($vacinacao);

        abort_if($registro === null, 404, 'Vacinação não encontrada.');

        return response()->json([
            'animal' => $animal->paraListagem(),
            ...$this->calendario->detalheAplicacao($animal, $registro),
        ]);
    }
}
