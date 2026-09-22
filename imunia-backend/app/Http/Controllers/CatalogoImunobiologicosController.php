<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImunobiologicoRequest;
use App\Http\Requests\UpdateImunobiologicoRequest;
use App\Models\Imunobiologico;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * X01 — catálogo de imunobiológicos (RF23). Único ponto de escrita da
 * tabela que RN30 usa para restringir o que pode ser registrado como
 * aplicação (RF25) — daqui em diante, nenhum imunobiológico chega ao
 * catálogo sem passar por este controlador.
 */
class CatalogoImunobiologicosController extends Controller
{
    /**
     * A lista inteira, sem paginação: o catálogo é de dezenas de itens, não
     * de milhares, e o painel de edição precisa da linha já carregada para
     * abrir sem nova requisição (§8.5 do briefing).
     */
    public function index(Request $request): JsonResponse
    {
        $this->exigirAdminPlataforma($request);

        $especie = $request->query('especie');
        $classificacao = $request->query('classificacao');

        $imunobiologicos = Imunobiologico::query()
            // Só o acervo da plataforma. O que cada clínica cadastrou para si
            // (A04) não é catálogo a manter aqui: a diretriz nunca o examinou,
            // e editá-lo daqui mudaria o cálculo de um lembrete que não é desta
            // administração. A recusa por item vem de `exigirItemDaPlataforma`;
            // este filtro é o que impede que ele apareça para ser tentado.
            ->whereNull('prestador_id')
            ->when($especie && $especie !== 'todas', fn ($query) => $query->where('especie_destino', $especie))
            ->when($classificacao, fn ($query) => $query->where('classificacao', $classificacao))
            ->orderByDesc('ativo')
            ->orderBy('nome_comercial')
            ->get();

        return response()->json([
            'imunobiologicos' => $imunobiologicos->map(fn (Imunobiologico $item) => $this->representar($item)),
        ]);
    }

    /**
     * A chave nasce aqui, gerada pela FormRequest a partir da denominação
     * técnica (RN30) — o formulário nunca a pede, e não é ela quem varia
     * quando o cadastro é corrigido depois.
     */
    public function store(StoreImunobiologicoRequest $request): JsonResponse
    {
        $imunobiologico = Imunobiologico::create($request->comChave());

        return response()->json(['imunobiologico' => $this->representar($imunobiologico)], 201);
    }

    /**
     * A situação ativo/inativo não é campo deste formulário (RF23b) — só a
     * denominação, o fabricante, a espécie, os agentes, a classificação e a
     * via podem ser corrigidos por aqui.
     */
    public function update(UpdateImunobiologicoRequest $request, Imunobiologico $imunobiologico): JsonResponse
    {
        $this->exigirItemDaPlataforma($imunobiologico);

        $imunobiologico->update($request->validated());

        return response()->json(['imunobiologico' => $this->representar($imunobiologico)]);
    }

    /**
     * Inativação, nunca exclusão (RF23b): impede o item em novo registro de
     * vacinação, e não afeta as aplicações já gravadas com ele.
     */
    public function inativar(Request $request, Imunobiologico $imunobiologico): JsonResponse
    {
        $this->exigirAdminPlataforma($request);
        $this->exigirItemDaPlataforma($imunobiologico);

        $imunobiologico->update(['ativo' => false]);

        return response()->json(['imunobiologico' => $this->representar($imunobiologico)]);
    }

    public function reativar(Request $request, Imunobiologico $imunobiologico): JsonResponse
    {
        $this->exigirAdminPlataforma($request);
        $this->exigirItemDaPlataforma($imunobiologico);

        $imunobiologico->update(['ativo' => true]);

        return response()->json(['imunobiologico' => $this->representar($imunobiologico)]);
    }

    private function exigirAdminPlataforma(Request $request): void
    {
        /** @var User $usuario */
        $usuario = $request->user();

        abort_if(! $usuario->admin_plataforma, 403, 'Esta área é da administração da plataforma.');
    }

    /**
     * O item próprio de uma clínica (A04) não existe para esta tela — e 404,
     * não 403, é o que diz isso. Responder "existe, mas você não pode" contaria
     * à administração da plataforma que alguma clínica cadastrou algum item,
     * que é informação do estabelecimento e não dela.
     */
    private function exigirItemDaPlataforma(Imunobiologico $imunobiologico): void
    {
        abort_if(! $imunobiologico->daPlataforma(), 404, 'Imunobiológico não encontrado.');
    }

    /**
     * @return array<string, mixed>
     */
    private function representar(Imunobiologico $imunobiologico): array
    {
        return [
            'id' => $imunobiologico->id,
            'chave' => $imunobiologico->chave,
            'nome_comercial' => $imunobiologico->nome_comercial,
            'nome_tecnico' => $imunobiologico->nome_tecnico,
            'fabricante' => $imunobiologico->fabricante,
            'agentes_cobertos' => $imunobiologico->agentes_cobertos,
            'especie_destino' => $imunobiologico->especie_destino,
            'classificacao' => $imunobiologico->classificacao,
            'via_administracao_usual' => $imunobiologico->via_administracao_usual,
            'ativo' => $imunobiologico->ativo,
        ];
    }
}
