<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Services\PendenciasVacinaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PendenciasVacinaisController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly PendenciasVacinaisService $pendencias)
    {
    }

    /**
     * V02 — painel de pendências vacinais (RF49). O âmbito é o do prestador
     * ativo e o das autorizações vigentes (RN48), como em V01: animal que
     * ninguém autorizou não figura no resultado, e é RF49c escrito no serviço.
     */
    public function index(Request $request): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoClinico($request);

        return response()->json([
            ...$this->pendencias->consultar(
                $prestador,
                $this->filtros($request),
                max(1, $request->integer('pagina', 1)),
            ),
            'vinculos' => $this->vinculosDe($usuario),
        ]);
    }

    /**
     * RF49b — "o resultado é exportável para acompanhamento da rechamada ativa".
     * Um arquivo que a equipe clínica risca conforme telefona, e por isso sai
     * com os mesmos filtros e a mesma ordem da tela: exportar outra coisa que
     * não o que está à vista tornaria o arquivo inconferível.
     *
     * Separador de ponto e vírgula e marca de ordem de byte porque o destino
     * provável é uma planilha em português — com vírgula, "Théo · cão" quebraria
     * em duas colunas na configuração regional brasileira.
     */
    public function exportar(Request $request): StreamedResponse
    {
        [, $prestador] = $this->contextoClinico($request);

        $filtros = $this->filtros($request);
        $linhas = $this->pendencias->paraExportacao($prestador, $filtros);
        $arquivo = 'pendencias-vacinais-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($linhas) {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\u{FEFF}");

            fputcsv($saida, [
                'Animal', 'Código', 'Espécie', 'Tutor', 'Imunobiológico',
                'Dose', 'Prevista para', 'Atraso', 'Última notificação',
            ], ';');

            foreach ($linhas as $linha) {
                fputcsv($saida, [
                    $linha['animal']['nome'],
                    $linha['animal']['codigo'],
                    $linha['animal']['especie'] === 'gato' ? 'Gato' : 'Cão',
                    $linha['tutor'],
                    $linha['imunobiologico'],
                    $linha['dose'],
                    // O arquivo é lido por gente, não por outro sistema: a data
                    // vai no formato em que a tela a mostrou.
                    date('d/m/Y', strtotime($linha['prevista_para'])),
                    $linha['atraso']['texto'],
                    $linha['ultima_notificacao']['texto'] ?? 'nunca notificado',
                ], ';');
            }

            fclose($saida);
        }, $arquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Os quatro filtros de RF49, saneados aqui e não em Form Request: o pedido
     * vem da própria interface, e valor fora da lista volta ao padrão em vez de
     * responder 422 — a tela padrão é resposta melhor a um endereço adulterado
     * do que a tela que não abre (mesma decisão de RF48b, em V01).
     *
     * @return array{dias: int, especie: ?string, imunobiologico: ?string, situacao: ?string}
     */
    private function filtros(Request $request): array
    {
        $dias = $request->integer('dias');

        return [
            'dias' => in_array($dias, PendenciasVacinaisService::PERIODOS_EM_DIAS, true)
                ? $dias
                : PendenciasVacinaisService::PERIODO_PADRAO_DIAS,
            'especie' => $this->dentroDaLista($request->string('especie')->toString(), ['cao', 'gato']),
            'imunobiologico' => $request->filled('imunobiologico')
                ? $request->string('imunobiologico')->toString()
                : null,
            'situacao' => $this->dentroDaLista(
                $request->string('situacao')->toString(),
                PendenciasVacinaisService::SITUACOES,
            ),
        ];
    }

    /**
     * @param list<string> $aceitos
     */
    private function dentroDaLista(string $valor, array $aceitos): ?string
    {
        return in_array($valor, $aceitos, true) ? $valor : null;
    }
}
