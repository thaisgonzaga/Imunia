<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarObitoRequest;
use App\Models\Animal;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * V12 — registrar óbito (RF22).
 *
 * Um verbo só, e sem serviço próprio: o óbito é uma escrita de cinco colunas no
 * próprio animal, e o padrão da casa admite o fluxo simples inline (P04). O que
 * V07 e V08 têm de serviço existe pela complexidade que eles carregam — anexos,
 * prévia, janela de duplicidade — e nada disso existe aqui.
 *
 * Não há verbo de desfazer, e não é omissão: RF22c torna o registro permanente,
 * e a correção é a retificação na forma de RF33 — fatia futura, como o
 * detalhe de óbito da linha do tempo.
 */
class RegistroDeObitoController extends Controller
{
    public function store(RegistrarObitoRequest $request): JsonResponse
    {
        /** @var User $profissional */
        $profissional = $request->profissional;
        /** @var Prestador $prestador */
        $prestador = $request->prestador;
        /** @var Animal $animal */
        $animal = $request->animal;

        // RF22c — registrado uma vez, o registro fica. A escrita é condicionada
        // ao próprio banco (`whereNull`), e não só à leitura feita acima:
        // dois profissionais com o modal aberto ao mesmo tempo gravariam um por
        // cima do outro, e o segundo apagaria a autoria do primeiro sem que
        // ninguém soubesse.
        $gravou = $animal->inativo() ? 0 : Animal::query()
            ->whereKey($animal->id)
            ->whereNull('obito_em')
            ->update([
                'obito_em' => $request->date('em'),
                'obito_causa' => $request->validated('causa'),
                'obito_registrado_por_user_id' => $profissional->id,
                'obito_prestador_id' => $prestador->id,
                'obito_registrado_crmv' => $request->crmv,
            ]);

        if ($gravou === 0) {
            $animal->refresh()->loadMissing('obitoRegistradoPor');

            // Estado da tela (§V12), não erro: quem tentou registrar de novo
            // termina sabendo o que já consta — data e autor —, como o
            // `ja_pendente` de V10 e pelo mesmo 409.
            return response()->json([
                'situacao' => 'ja_registrado',
                'em' => $animal->obito_em?->toDateString(),
                'registrado_por' => $animal->obitoRegistradoPor?->name,
                'message' => sprintf(
                    'O óbito deste animal já está registrado, em %s. O registro não pode ser excluído, apenas retificado.',
                    $animal->obito_em?->format('d/m/Y'),
                ),
            ], 409);
        }

        $animal->refresh();

        return response()->json([
            'em' => $animal->obito_em?->toDateString(),
            'causa' => $animal->obito_causa,
            'registrado_por' => $profissional->name,

            // §V12 — sucesso leva a V06 em estado de animal inativo. O contexto
            // vai junto, como em toda ligação do ambiente clínico (RF09b).
            'destino' => sprintf(
                '/clinica/animais/%s?prestador=%d',
                $animal->codigo,
                $prestador->id,
            ),
        ], 201);
    }
}
