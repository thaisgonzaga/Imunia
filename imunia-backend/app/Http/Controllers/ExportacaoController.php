<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmitirExportacaoRequest;
use App\Models\Animal;
use App\Models\Exportacao;
use App\Models\User;
use App\Services\ExportacaoDeHistoricoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportacaoController extends Controller
{
    public function __construct(private readonly ExportacaoDeHistoricoService $exportacoes) {}

    /**
     * T15 — emitir o histórico em PDF verificável (RF46). Mesma checagem de
     * âmbito de T05 e T07: a busca parte do tutor autenticado, e um código que
     * existe mas pertence a outro tutor responde 404 igual a um inexistente
     * (RN12).
     *
     * A geração é síncrona porque o prazo dela é critério de aceitação
     * (RF46d, RNF04): a resposta só volta com o documento já emitido, gravado
     * e disponível para download.
     */
    public function store(EmitirExportacaoRequest $request, string $codigo): JsonResponse
    {
        [$usuario, $animal] = $this->animalDoTutor($request, $codigo);

        $exportacao = $this->exportacoes->emitir(
            $animal,
            $usuario,
            $request->conteudo(),
            $request->meses(),
        );

        // Sem registro no recorte não há documento: um PDF autêntico e vazio
        // afirmaria, com selo da plataforma, que nada aconteceu — que não é o
        // que a ausência de registro diz (RN25).
        abort_if(
            $exportacao === null,
            422,
            'Não há registros no conteúdo escolhido. Ajuste a seleção antes de gerar o documento.',
        );

        return response()->json([
            'exportacao' => [
                'codigo' => $exportacao->codigo,
                'codigo_formatado' => $exportacao->codigoFormatado(),
                'resumo' => $exportacao->resumoAbreviado(),
                'emitido_em' => $exportacao->emitido_em->toIso8601String(),
                'link_verificacao' => $this->exportacoes->linkDeVerificacao($exportacao),
                'url_documento' => "/api/animais/{$animal->codigo}/exportacoes/{$exportacao->codigo}/documento",
            ],
        ], 201);
    }

    /**
     * O arquivo emitido. O caminho parte do animal, e não da emissão sozinha,
     * porque o âmbito é a titularidade: a emissão de um animal alheio responde
     * 404 igual a uma inexistente (RN12).
     *
     * O arquivo entregue é o que foi gravado na emissão, nunca uma segunda
     * geração: o resumo registrado assina aquele conteúdo, e regenerar sobre
     * dados que mudaram entregaria um documento divergente do próprio rodapé.
     */
    public function documento(Request $request, string $codigo, string $emissao): StreamedResponse
    {
        [, $animal] = $this->animalDoTutor($request, $codigo);

        $exportacao = Exportacao::query()
            ->where('codigo', Exportacao::normalizarCodigo($emissao))
            ->where('animal_id', $animal->id)
            ->first();

        abort_if($exportacao === null, 404, 'Documento não encontrado.');
        abort_unless(
            Storage::disk('local')->exists($exportacao->caminhoDoArquivo()),
            404,
            'O arquivo desta emissão não está mais disponível. Gere um novo documento.',
        );

        return Storage::disk('local')->download(
            $exportacao->caminhoDoArquivo(),
            "imunia-{$animal->codigo}-{$exportacao->codigo}.pdf",
        );
    }

    /**
     * @return array{0: User, 1: Animal}
     */
    private function animalDoTutor(Request $request, string $codigo): array
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        $animal = $tutor->animais()->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        return [$usuario, $animal];
    }
}
