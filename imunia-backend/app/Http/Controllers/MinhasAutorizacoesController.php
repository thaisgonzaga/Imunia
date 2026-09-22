<?php

namespace App\Http\Controllers;

use App\Models\Autorizacao;
use App\Models\Tutor;
use App\Models\User;
use App\Services\MinhasAutorizacoesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T12 — minhas autorizações (RF41), com a revogação (RF39) e a renovação
 * (RF40c) acionáveis da própria relação, como manda RF41a.
 *
 * Controlador à parte do de T11 de propósito: lá o assunto é o consentimento
 * sendo formado — três requisições, código por e-mail, nada gravado antes da
 * última. Aqui ele já existe, e o que se faz é conservá-lo ou desfazê-lo.
 */
class MinhasAutorizacoesController extends Controller
{
    public function __construct(private readonly MinhasAutorizacoesService $autorizacoes) {}

    /**
     * RF41 — vigentes, expiradas e revogadas, por animal, com data de concessão,
     * prazo e situação.
     */
    public function index(Request $request): JsonResponse
    {
        [, $tutor] = $this->doTutor($request);

        return response()->json($this->autorizacoes->listar($tutor));
    }

    /**
     * RF39 — revogar, a qualquer tempo e sem justificativa. Nenhum campo de
     * motivo, nenhuma confirmação por código: a explicação do alcance do ato
     * cabe à tela (RF39d), e o servidor não cobra do tutor um preço para
     * desfazer o que ele mesmo fez.
     */
    public function destroy(Request $request, Autorizacao $autorizacao): JsonResponse
    {
        [, $tutor] = $this->doTutor($request);

        $this->conferirTitularidade($autorizacao, $tutor);

        // Já revogada é pedido repetido, não erro: a tela do tutor que tocou
        // duas vezes deve terminar no mesmo lugar da que tocou uma.
        if ($autorizacao->revogada_em === null) {
            $this->autorizacoes->revogar($autorizacao);
        }

        return response()->json([
            'situacao' => 'revogada',
            'revogada_em' => $autorizacao->revogada_em->toDateString(),
            'message' => sprintf(
                '%s deixou de ver o histórico de %s.',
                $autorizacao->prestador->nome,
                $autorizacao->animal->nome,
            ),
        ]);
    }

    /**
     * RF40c — renovar em ato único. Só o que ainda está de pé se renova: depois
     * do prazo o consentimento acabou, e retomá-lo é o fluxo de T11, com código.
     */
    public function renovar(Request $request, Autorizacao $autorizacao): JsonResponse
    {
        [$usuario, $tutor] = $this->doTutor($request);

        $this->conferirTitularidade($autorizacao, $tutor);

        if (! $autorizacao->estaVigente()) {
            return response()->json([
                'situacao' => 'encerrada',
                'message' => 'Esta autorização já terminou. Para retomá-la, autorize a clínica de novo.',
            ], 409);
        }

        $renovada = $this->autorizacoes->renovar($autorizacao, $usuario);

        return response()->json([
            'situacao' => 'renovada',
            'renovacao' => [
                'id' => $renovada->id,
                'expira_em' => $renovada->expira_em->toDateString(),
                'prazo_em_dias' => Autorizacao::PRAZO_DIAS,
            ],
            'message' => sprintf(
                '%s continua vendo o histórico de %s por mais %d dias.',
                $renovada->prestador->nome,
                $renovada->animal->nome,
                Autorizacao::PRAZO_DIAS,
            ),
        ], 201);
    }

    /**
     * A autorização de outro tutor não existe para este: 404, e não 403, porque
     * a segunda resposta confirmaria que a linha existe.
     */
    private function conferirTitularidade(Autorizacao $autorizacao, Tutor $tutor): void
    {
        abort_if(
            $autorizacao->animal?->tutor_id !== $tutor->id,
            404,
            'Autorização não encontrada.',
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
