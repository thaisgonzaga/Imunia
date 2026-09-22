<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Services\PainelVeterinarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PainelVeterinarioController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly PainelVeterinarioService $painel)
    {
    }

    /**
     * V01 — painel do veterinário (RF48). Uma requisição só, como em T01: os
     * quatro indicadores, a lista de atendidos e as duas colunas da direita são
     * uma leitura do mesmo âmbito, e parti-la em quatro chamadas só multiplicaria
     * a chance de uma delas esquecer o filtro de RN48.
     */
    public function show(Request $request): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoClinico($request);

        return response()->json([
            ...$this->painel->montar(
                $usuario,
                $prestador,
                $this->intervaloEmDias($request),
                $this->pagina($request),
            ),

            'vinculos' => $this->vinculosDe($usuario),
        ]);
    }

    /**
     * RF48b — o intervalo é ajustável, entre os três oferecidos. Valor fora da
     * lista volta ao padrão em vez de responder 422: o parâmetro vem da própria
     * interface, e uma tela que não abre é resposta pior a um endereço adulterado
     * do que a tela padrão.
     */
    private function intervaloEmDias(Request $request): int
    {
        $dias = $request->integer('dias');

        return in_array($dias, PainelVeterinarioService::INTERVALOS_EM_DIAS, true)
            ? $dias
            : PainelVeterinarioService::INTERVALO_PADRAO_DIAS;
    }

    private function pagina(Request $request): int
    {
        return max(1, $request->integer('pagina', 1));
    }
}
