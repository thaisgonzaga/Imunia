<?php

namespace App\Http\Controllers;

use App\Models\SolicitacaoAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Services\SolicitacoesDeAcessoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T13 — solicitações de acesso (RF38), do lado do tutor.
 *
 * Duas leituras e um ato, e o ato é apenas o de negar. Autorizar não passa por
 * aqui: quem autoriza é T11, com o código enviado ao endereço do tutor (RF37),
 * e a tela desta fatia encaminha para lá com o prestador e o animal já
 * resolvidos. Aceitar um pedido por um toque nesta rota seria criar um segundo
 * caminho de concessão sem confirmação — exatamente o que RF37 existe para
 * impedir.
 */
class SolicitacoesAcessoController extends Controller
{
    public function __construct(private readonly SolicitacoesDeAcessoService $solicitacoes) {}

    /**
     * RF38c — quem solicitou, quando e para qual animal.
     */
    public function index(Request $request): JsonResponse
    {
        [, $tutor] = $this->doTutor($request);

        return response()->json($this->solicitacoes->listar($tutor));
    }

    /**
     * O contador da aba de navegação. Rota própria, e mínima, porque a moldura
     * do tutor a consulta em toda entrada no ambiente: trazer a lista inteira
     * para desenhar um número seria carregar o histórico de pedidos em telas
     * que não falam deles.
     */
    public function pendentes(Request $request): JsonResponse
    {
        [, $tutor] = $this->doTutor($request);

        return response()->json(['pendentes' => $this->solicitacoes->contarPendentes($tutor)]);
    }

    /**
     * A recusa (RF38). Sem justificativa, sem código e sem confirmação por
     * e-mail: negar acesso não pode ser mais difícil do que concedê-lo, e o
     * pedido recusado não retira do prestador nada que ele já tivesse.
     */
    public function recusar(Request $request, SolicitacaoAcesso $solicitacao): JsonResponse
    {
        [, $tutor] = $this->doTutor($request);

        $this->conferirTitularidade($solicitacao, $tutor);

        // Já recusado é pedido repetido, e não erro: quem tocou duas vezes
        // termina onde terminaria quem tocou uma.
        if ($solicitacao->recusada_em !== null) {
            return response()->json($this->recusado($solicitacao));
        }

        if (! $solicitacao->estaPendente()) {
            return response()->json([
                'situacao' => $solicitacao->situacao(),
                'message' => $solicitacao->atendida_em !== null
                    ? 'Você já autorizou este pedido. Para encerrar o acesso, use as suas autorizações.'
                    : 'Este pedido caducou sozinho e já não aguarda resposta.',
            ], 409);
        }

        $this->solicitacoes->recusar($solicitacao);

        return response()->json($this->recusado($solicitacao));
    }

    /**
     * @return array<string, mixed>
     */
    private function recusado(SolicitacaoAcesso $solicitacao): array
    {
        return [
            'situacao' => 'recusada',
            'recusada_em' => $solicitacao->recusada_em->toDateString(),
            'message' => sprintf(
                '%s não vai acompanhar o histórico de %s.',
                $solicitacao->prestador->nome,
                $solicitacao->animal->nome,
            ),
        ];
    }

    /**
     * O pedido dirigido a outro tutor não existe para este: 404, e não 403,
     * porque a segunda resposta confirmaria que a linha existe.
     */
    private function conferirTitularidade(SolicitacaoAcesso $solicitacao, Tutor $tutor): void
    {
        abort_if(
            $solicitacao->animal?->tutor_id !== $tutor->id,
            404,
            'Pedido não encontrado.',
        );
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
