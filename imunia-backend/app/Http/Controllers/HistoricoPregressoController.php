<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHistoricoPregressoRequest;
use App\Models\User;
use App\Services\HistoricoPregressoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoricoPregressoController extends Controller
{
    public function __construct(private readonly HistoricoPregressoService $pregresso)
    {
    }

    /**
     * T09 — o que o formulário precisa saber antes de ser preenchido: de que
     * animal se trata, que vacinas o catálogo oferece para a espécie dele
     * (RF23) e em nome de quem o registro será lançado.
     *
     * `lancado_por` vem no mesmo formato que a carteira usa nas aplicações já
     * gravadas, de propósito: a pré-visualização ao vivo monta a frase de
     * procedência com a mesma função que o selo de lote, e assim o que o
     * tutor vê antes de confirmar é literalmente o que ele verá depois.
     */
    public function opcoes(Request $request, string $codigo): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        $animal = $tutor->animais()->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        return response()->json([
            'animal' => $animal->paraListagem(),
            'imunobiologicos' => $this->pregresso->catalogoPara($animal),
            'lancado_por' => [
                'nome' => $usuario->name,
                'em' => now()->toDateString(),
            ],
        ]);
    }

    /**
     * RF29 — o lançamento. O âmbito do tutor e a existência do animal já foram
     * resolvidos pela FormRequest, que precisa deles para responder 404 antes
     * de 422; o que resta ao controlador é o que lhe cabe: chamar o serviço e
     * devolver a resposta (decisoes.md §5.2).
     */
    public function store(StoreHistoricoPregressoRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $vacinacao = $this->pregresso->lancar(
            $request->animal,
            $usuario,
            $request->validated(),
        );

        // Só o id: é o que T05 precisa para destacar o registro recém-lançado,
        // e a carteira inteira ela busca por conta própria, de uma fonte só.
        return response()->json(['vacinacao' => ['id' => $vacinacao->id]], 201);
    }
}
