<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmarAutorizacaoRequest;
use App\Http\Requests\IniciarAutorizacaoRequest;
use App\Models\Autorizacao;
use App\Models\ConfirmacaoDeAutorizacao;
use App\Models\Tutor;
use App\Models\User;
use App\Services\ConcessaoDeAutorizacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T11 — conceder autorização (RF36, RF37).
 *
 * O fluxo tem três requisições porque tem três atos: escolher, receber o código
 * e confirmar. A do meio não grava autorização alguma, e é essa separação que
 * dá sentido ao requisito — o consentimento não é a escolha feita na tela, é a
 * confirmação que só chega pelo canal do titular.
 */
class AutorizacaoController extends Controller
{
    public function __construct(private readonly ConcessaoDeAutorizacaoService $concessao) {}

    /**
     * O que os três passos precisam saber, numa resposta só: entre quais
     * estabelecimentos escolher, quais animais o tutor tem, quais deles já estão
     * autorizados onde, e se o e-mail está confirmado (RF37b).
     */
    public function opcoes(Request $request): JsonResponse
    {
        [$usuario, $tutor] = $this->doTutor($request);

        $prestador = $request->integer('prestador');

        return response()->json($this->concessao->opcoes(
            $usuario,
            $tutor,
            $prestador > 0 ? $prestador : null,
        ));
    }

    /**
     * Passo 2 → 3: abre a confirmação e envia o código. A resposta traz o
     * resumo que o passo 3 exibe antes do campo — e nunca o código (RF37c).
     */
    public function store(IniciarAutorizacaoRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        $confirmacao = $this->concessao->iniciar(
            $usuario,
            $request->prestador,
            $request->animais->pluck('id')->all(),
        );

        return response()->json([
            'confirmacao' => $this->concessao->resumo($confirmacao, $request->tutor),
        ], 201);
    }

    /**
     * O ato. Cada desfecho tem situação nomeada, porque o passo 3 desenha uma
     * tela diferente para cada um deles — e porque "não deu certo" não é
     * resposta aceitável na tela mais importante do sistema.
     */
    public function confirmar(
        ConfirmarAutorizacaoRequest $request,
        ConfirmacaoDeAutorizacao $confirmacao,
    ): JsonResponse {
        // A posse — a confirmação é deste usuário — já foi conferida pela
        // FormRequest, que precisa dela para responder 404 antes de qualquer
        // 422 sobre o código.
        if ($confirmacao->foiConfirmada()) {
            return response()->json([
                'situacao' => 'ja_confirmada',
                'message' => 'Esta autorização já foi concedida.',
            ], 409);
        }

        // A pausa vem antes da expiração: quem está bloqueado não recomeça
        // pedindo código novo, que é o que a tela de expirado ofereceria.
        if ($confirmacao->bloqueada()) {
            return $this->respostaDeBloqueio($confirmacao);
        }

        if ($confirmacao->expirou()) {
            return response()->json([
                'situacao' => 'codigo_expirado',
                'message' => 'O prazo deste código terminou. Peça um novo para concluir a autorização.',
            ], 410);
        }

        if (! $confirmacao->codigoConfere($request->string('codigo')->toString())) {
            $this->concessao->registrarCodigoErrado($confirmacao);

            if ($confirmacao->bloqueada()) {
                return $this->respostaDeBloqueio($confirmacao);
            }

            return response()->json([
                'situacao' => 'codigo_incorreto',
                'message' => 'Este código não confere. Confira o e-mail mais recente e digite de novo.',
                'tentativas_restantes' => $confirmacao->tentativasRestantes(),
                // No formato de erro de campo também, para que a tela marque o
                // campo como as demais telas do sistema marcam.
                'errors' => ['codigo' => ['Este código não confere.']],
            ], 422);
        }

        $concedidas = $this->concessao->confirmar($confirmacao, $request->tutor);

        return response()->json([
            'situacao' => 'concedida',
            'concessao' => [
                'prestador' => [
                    'id' => $confirmacao->prestador->id,
                    'nome' => $confirmacao->prestador->nome,
                    'tipo_rotulo' => $confirmacao->prestador->tipoRotulo(),
                    'municipio' => $confirmacao->prestador->municipio,
                    'uf' => $confirmacao->prestador->uf,
                ],
                'animais' => array_map(
                    fn (Autorizacao $autorizacao) => [
                        'codigo' => $autorizacao->animal->codigo,
                        'nome' => $autorizacao->animal->nome,
                    ],
                    $concedidas,
                ),
                'prazo_em_dias' => Autorizacao::PRAZO_DIAS,
                'expira_em' => $concedidas[0]->expira_em->toDateString(),
            ],
        ], 201);
    }

    /**
     * Código novo para a mesma escolha. Só depois que o anterior vence: enquanto
     * o contador corre, o código que está no e-mail do tutor funciona, e emitir
     * outro apenas o invalidaria — deixando quem já tinha aberto a mensagem com
     * um código morto na mão.
     */
    public function reenviar(Request $request, ConfirmacaoDeAutorizacao $confirmacao): JsonResponse
    {
        [$usuario, $tutor] = $this->doTutor($request);

        abort_if($confirmacao->user_id !== $usuario->id, 404, 'Autorização não encontrada.');

        if ($confirmacao->foiConfirmada()) {
            return response()->json([
                'situacao' => 'ja_confirmada',
                'message' => 'Esta autorização já foi concedida.',
            ], 409);
        }

        if ($confirmacao->bloqueada()) {
            return $this->respostaDeBloqueio($confirmacao);
        }

        if (! $confirmacao->expirou()) {
            return response()->json([
                'situacao' => 'reenvio_bloqueado',
                'message' => 'O código enviado ainda vale. O reenvio libera quando o tempo terminar.',
                'segundos_restantes' => $confirmacao->segundosRestantes(),
            ], 429);
        }

        $this->concessao->reenviar($confirmacao);

        return response()->json([
            'confirmacao' => $this->concessao->resumo($confirmacao, $tutor),
        ]);
    }

    private function respostaDeBloqueio(ConfirmacaoDeAutorizacao $confirmacao): JsonResponse
    {
        return response()->json([
            'situacao' => 'bloqueada',
            'message' => sprintf(
                'Bloqueamos esta autorização por %d minutos. Nada foi autorizado.',
                ConfirmacaoDeAutorizacao::BLOQUEIO_EM_MINUTOS,
            ),
            'segundos_restantes' => $confirmacao->segundosDeBloqueio(),
        ], 429);
    }

    /**
     * @return array{0: User, 1: Tutor}
     */
    private function doTutor(Request $request): array
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        return [$usuario, $tutor];
    }
}
