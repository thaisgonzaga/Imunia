<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Services\RegistrosDaClinicaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrosDaClinicaController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly RegistrosDaClinicaService $registros) {}

    /**
     * Relação de registros da clínica — o destino "Registros" da barra lateral
     * (§5.3). O âmbito é a autoria (RN40), não a autorização vigente — o
     * inverso da relação de animais, e a razão está no comentário de
     * `RegistrosDaClinicaService`.
     *
     * Sem registro de acesso, como toda listagem agregada do ambiente clínico
     * — e aqui com um motivo a mais: tudo o que este livro relaciona é
     * produção do próprio prestador, e o livro de T14 conta ao tutor o acesso
     * ao que é de terceiros (RN49), do qual não há nenhum nesta resposta.
     */
    public function index(Request $request): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoClinico($request);

        return response()->json([
            ...$this->registros->consultar(
                $prestador,
                $this->filtros($request),
                max(1, $request->integer('pagina', 1)),
            ),
            'vinculos' => $this->vinculosDe($usuario),
        ]);
    }

    /**
     * Saneados aqui e não em Form Request, como na relação de animais: o
     * pedido vem da própria interface, e valor fora da lista volta ao padrão
     * em vez de responder 422 (RF48b). O id de profissional só tem o formato
     * conferido aqui — quem sabe se ele assina algo neste livro é o serviço,
     * e é lá que o valor estranho volta ao padrão.
     *
     * @return array{tipo: ?string, profissional: ?int}
     */
    private function filtros(Request $request): array
    {
        $tipo = $request->string('tipo')->toString();
        $profissional = $request->integer('profissional');

        return [
            'tipo' => in_array($tipo, RegistrosDaClinicaService::TIPOS, true) ? $tipo : null,
            'profissional' => $profissional > 0 ? $profissional : null,
        ];
    }
}
