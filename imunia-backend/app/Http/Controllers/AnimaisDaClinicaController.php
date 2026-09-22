<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Services\AnimaisDaClinicaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnimaisDaClinicaController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly AnimaisDaClinicaService $animais) {}

    /**
     * Relação de animais da clínica — o destino "Animais" da barra lateral
     * (§5.3). O âmbito é o de V01 e V02: prestador ativo (RF48a) e autorização
     * vigente (RN48).
     *
     * Sem registro de acesso, pela mesma razão de V01 e V02: listagem agregada
     * não é abertura de histórico. O acesso "por ver" é o da ficha (RF52b), e
     * é para lá que cada linha desta lista leva.
     */
    public function index(Request $request): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoClinico($request);

        return response()->json([
            ...$this->animais->consultar(
                $prestador,
                $this->filtros($request),
                max(1, $request->integer('pagina', 1)),
            ),
            'vinculos' => $this->vinculosDe($usuario),
        ]);
    }

    /**
     * Saneados aqui e não em Form Request, como em V02: o pedido vem da própria
     * interface, e valor fora da lista volta ao padrão em vez de responder 422
     * — a tela padrão é resposta melhor a um endereço adulterado do que a tela
     * que não abre (RF48b).
     *
     * @return array{especie: ?string, situacao: ?string}
     */
    private function filtros(Request $request): array
    {
        return [
            'especie' => $this->dentroDaLista($request->string('especie')->toString(), ['cao', 'gato']),
            'situacao' => $this->dentroDaLista(
                $request->string('situacao')->toString(),
                AnimaisDaClinicaService::SITUACOES,
            ),
        ];
    }

    /**
     * @param  list<string>  $aceitos
     */
    private function dentroDaLista(string $valor, array $aceitos): ?string
    {
        return in_array($valor, $aceitos, true) ? $valor : null;
    }
}
