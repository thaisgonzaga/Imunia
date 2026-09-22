<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarAcessosRequest;
use App\Models\Tutor;
use App\Models\User;
use App\Services\LivroDeAcessosService;
use Illuminate\Http\JsonResponse;

/**
 * T14 — quem acessou meus dados (RF53).
 *
 * Uma leitura e nenhum ato. A revogação que a tela oferece em cada linha
 * (RF53b) é a de T12, e chamá-la de lá é a resposta certa: o efeito é o mesmo,
 * o texto que o tutor lê antes de confirmar é o mesmo (RF39d), e um segundo
 * caminho de revogação seria um segundo lugar onde esquecer de comunicar o
 * prestador.
 *
 * Não há aqui nada que escreva no livro. Não deve haver: RF52a diz que o log é
 * imutável e não editável por papel algum, e o titular dos dados não é exceção
 * a isso — é a razão de ser. Um registro que o interessado pudesse apagar não
 * provaria coisa alguma a respeito de quem o consultou.
 */
class LivroDeAcessosController extends Controller
{
    public function __construct(private readonly LivroDeAcessosService $acessos) {}

    /**
     * RF53a — prestador, profissional, data e hora, sob o recorte que a tela
     * pediu: por animal, por período e, quando se chega de T12, por prestador.
     */
    public function index(ConsultarAcessosRequest $request): JsonResponse
    {
        $tutor = $this->doTutor($request);

        return response()->json($this->acessos->listar($tutor, $request->filtro()));
    }

    private function doTutor(ConsultarAcessosRequest $request): Tutor
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        // O livro de acessos é do titular dos dados. O veterinário não o
        // consulta por tela alguma: ele é quem figura nele.
        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        return $tutor;
    }
}
