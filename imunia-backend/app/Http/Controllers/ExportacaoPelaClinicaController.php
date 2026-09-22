<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\EmitirExportacaoRequest;
use App\Models\Animal;
use App\Models\Exportacao;
use App\Models\Prestador;
use App\Services\ExportacaoDeHistoricoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportacaoPelaClinicaController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly ExportacaoDeHistoricoService $exportacoes) {}

    /**
     * V06 → T15 — emitir o documento pelo ambiente clínico (RF46 nomeia o
     * veterinário como ator). Não é a rota de T15 com outro caminho: lá o
     * âmbito é a titularidade do tutor, e um animal alheio responde 404
     * (RN12); aqui é a autorização vigente do prestador ativo, e a falta dela
     * é 403 que nomeia o caminho — negar a existência de quem o profissional
     * já encontrou o mandaria procurar de novo (mesma régua de V07).
     *
     * O documento emitido é o mesmo de T15, byte a byte no que ele afirma:
     * mesmo conteúdo, mesmo resumo, mesma verificação pública. O que esta
     * porta acrescenta é a prestação de contas de RN49, gravada no serviço
     * antes da emissão.
     */
    public function store(EmitirExportacaoRequest $request, string $codigo): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);
        $animal = $this->animalSobAutorizacao($codigo, $prestador);

        $exportacao = $this->exportacoes->emitirPelaClinica(
            $animal,
            $profissional,
            $prestador,
            $request->conteudo(),
            $request->meses(),
        );

        // Sem registro no recorte não há documento, pela mesma razão de T15:
        // um PDF autêntico e vazio afirmaria, com selo da plataforma, que nada
        // aconteceu (RN25).
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

                // O contexto viaja no endereço do download porque a rota o
                // reverifica: sem ele, o pedido recairia no primeiro vínculo
                // do profissional, que pode não ser o prestador autorizado.
                'url_documento' => "/api/clinica/animais/{$animal->codigo}/exportacoes/"
                    ."{$exportacao->codigo}/documento?prestador={$prestador->id}",
            ],
        ], 201);
    }

    /**
     * O arquivo emitido, pela porta clínica. A autorização é conferida a cada
     * pedido, e não uma vez na emissão — a mesma regra do anexo (RF32c): uma
     * autorização revogada entre emitir e baixar tem que fechar o arquivo.
     *
     * O download não grava linha nova de RN49: a emissão é o ato, já prestou
     * contas, e o arquivo é o artefato dela — relançar a linha a cada clique em
     * "Baixar" encheria de repetições o livro que RF53 promete legível.
     */
    public function documento(Request $request, string $codigo, string $emissao): StreamedResponse
    {
        [, $prestador] = $this->contextoClinico($request);
        $animal = $this->animalSobAutorizacao($codigo, $prestador);

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

    private function animalSobAutorizacao(string $codigo, Prestador $prestador): Animal
    {
        $animal = Animal::query()->where('codigo', $codigo)->first();

        // Código inexistente é 404 para todo mundo: não há cadastro cuja
        // existência se pudesse revelar.
        abort_if($animal === null, 404, 'Animal não encontrado.');

        abort_if(
            ! $animal->autorizacoes()->where('prestador_id', $prestador->id)->vigente()->exists(),
            403,
            'Este animal não está sob autorização vigente para o prestador ativo.',
        );

        return $animal;
    }
}
