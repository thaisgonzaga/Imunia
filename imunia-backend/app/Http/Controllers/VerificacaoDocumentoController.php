<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerificarDocumentoRequest;
use App\Models\Exportacao;
use Illuminate\Http\JsonResponse;

class VerificacaoDocumentoController extends Controller
{
    /**
     * P09 — verificação pública do documento exportado (RF47). É a única rota
     * do sistema que dispensa autenticação (RN01), e a resposta é deliberadamente
     * pobre: autenticidade, data da emissão e o animal a que se refere, sem uma
     * palavra de conteúdo clínico, de tutor ou de prestador (RN47).
     *
     * Os três desfechos da verificação — autêntico, não localizado, divergente —
     * saem todos em 200. Um 404 para o código inexistente responderia, pelo
     * próprio status, a pergunta que RF47c manda não responder.
     */
    public function show(VerificarDocumentoRequest $request): JsonResponse
    {
        $exportacao = Exportacao::localizar($request->codigo());

        if ($exportacao === null) {
            return response()->json(['situacao' => 'nao_localizado']);
        }

        $resumoInformado = $request->resumo();

        // O resumo só chega quando a entrada foi pelo QR Code, que o carrega
        // junto do código. Na digitação manual não há o que confrontar aqui: a
        // comparação fica com o conferente, contra o rodapé impresso.
        if ($resumoInformado !== null && ! $exportacao->confere($resumoInformado)) {
            return response()->json([
                'situacao' => 'divergente',
                'resumo' => $exportacao->resumoAbreviado(),
            ]);
        }

        return response()->json([
            'situacao' => 'autentico',
            'emitido_em' => $exportacao->emitido_em->toDateString(),
            'animal' => [
                'nome' => $exportacao->animal_nome,
                'especie' => $exportacao->animal_especie,
            ],
            'resumo' => $exportacao->resumoAbreviado(),
        ]);
    }
}
