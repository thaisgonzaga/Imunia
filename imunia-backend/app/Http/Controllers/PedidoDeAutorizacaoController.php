<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\SolicitarAutorizacaoRequest;
use App\Services\SolicitacoesDeAcessoService;
use Illuminate\Http\JsonResponse;

/**
 * V10 — solicitar autorização ao tutor (RF38), do lado de quem pede.
 *
 * Controlador separado de `SolicitacoesAcessoController` de propósito: aquele é
 * o ambiente do tutor, e a verificação de porta é oposta — lá se exige a
 * titularidade, aqui o vínculo de veterinário. Um controlador que servisse aos
 * dois seria um lugar onde as duas guardas podem ser trocadas uma pela outra.
 *
 * O pedido é o único ato que o prestador pratica sobre histórico que não é seu,
 * e ele não confere acesso algum (RF38a): a resposta não traz nome, contato,
 * relação de animais nem contagem (RN12) — nem mesmo quando o pedido não cria
 * linha alguma, porque distinguir esses casos já seria contar.
 */
class PedidoDeAutorizacaoController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly SolicitacoesDeAcessoService $solicitacoes) {}

    public function store(SolicitarAutorizacaoRequest $request): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $resultado = $this->solicitacoes->solicitar(
            $profissional,
            $prestador,
            $request->termo(),
            $request->mensagem(),
        );

        // Pedido repetido e alvo já autorizado são estados da tela (V10), e o
        // 409 é o que separa os dois desfechos do envio bem-sucedido sem que a
        // tela precise adivinhar pela frase.
        return response()->json(
            $resultado,
            $resultado['situacao'] === 'enviada' ? 201 : 409,
        );
    }
}
