<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AlcancaAnimal;
use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\RetificarAtendimentoRequest;
use App\Http\Requests\RetificarVacinacaoRequest;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\Vacinacao;
use App\Services\RegistroClinicoService;
use App\Services\RetificacaoDeRegistroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * V09 — o registro clínico visto pelo veterinário, e a sua retificação (RF31,
 * RF25, RF33).
 *
 * Quatro verbos e nenhum deles é `PUT`, `PATCH` ou `DELETE`. A ausência é o
 * requisito, não um recorte: RF33a exige que nenhum caminho da aplicação
 * permita sobrescrever ou excluir registro clínico confirmado, e as duas rotas
 * de escrita daqui **criam** — cada uma grava um registro novo que aponta para
 * o que corrige, e nenhuma toca no que já estava lá.
 *
 * As duas leituras existem porque T06 e T08, que mostram os mesmos registros,
 * são telas do tutor: elas partem da titularidade e respondem 403 a quem
 * escreveu o prontuário. Aqui o âmbito é o contexto clínico do prestador
 * ativo, abrir registro alheio grava a linha do livro de acessos (RN49)
 * e a resposta diz se a ação de retificar cabe a quem está lendo (RN27, RNF09).
 */
class RegistroClinicoController extends Controller
{
    use AlcancaAnimal, ResolvePrestadorAtivo;

    public function __construct(
        private readonly RegistroClinicoService $registros,
        private readonly RetificacaoDeRegistroService $retificacao,
    ) {}

    public function atendimento(Request $request, string $codigo, int $atendimento): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $animal = $this->animalAutorizado($codigo, $prestador);

        /** @var Atendimento|null $registro */
        $registro = $animal->atendimentos()->find($atendimento);

        abort_if($registro === null, 404, 'Atendimento não encontrado.');

        return response()->json([
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'vinculos' => $this->vinculosDe($profissional),
            ...$this->registros->montarAtendimento($profissional, $prestador, $animal, $registro),
        ]);
    }

    public function vacinacao(Request $request, string $codigo, int $vacinacao): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $animal = $this->animalAutorizado($codigo, $prestador);

        /** @var Vacinacao|null $registro */
        $registro = $animal->vacinacoes()->find($vacinacao);

        abort_if($registro === null, 404, 'Aplicação não encontrada.');

        return response()->json([
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'vinculos' => $this->vinculosDe($profissional),
            ...$this->registros->montarVacinacao($profissional, $prestador, $animal, $registro),
        ]);
    }

    /**
     * RF33 — a retificação do prontuário. O âmbito, a autoria e a exigência de
     * que algo tenha mudado ficam na FormRequest, que os resolve antes de este
     * método existir.
     */
    public function retificarAtendimento(RetificarAtendimentoRequest $request): JsonResponse
    {
        $retificacao = $this->retificacao->retificarAtendimento(
            $request->profissional,
            $request->prestador,
            $request->original,
            $request->validated(),
        );

        return response()->json([
            'id' => $retificacao->id,
            'titulo' => $retificacao->titulo,

            // O sucesso abre a versão corrigida, e não a ficha: o profissional
            // acabou de afirmar uma coisa que fica permanente, e o que ele
            // precisa ver em seguida é o que ficou escrito — com o original
            // ligado a ela, que é o que a tela de detalhe mostra.
            'destino' => sprintf(
                '/clinica/animais/%s/atendimentos/%d?prestador=%d',
                $request->animal->codigo,
                $retificacao->id,
                $request->prestador->id,
            ),
        ], 201);
    }

    public function retificarVacinacao(RetificarVacinacaoRequest $request): JsonResponse
    {
        $retificacao = $this->retificacao->retificarVacinacao(
            $request->profissional,
            $request->prestador,
            $request->original,
            $request->validated(),
        );

        return response()->json([
            'id' => $retificacao->id,
            'destino' => sprintf(
                '/clinica/animais/%s/vacinas/%d?prestador=%d',
                $request->animal->codigo,
                $retificacao->id,
                $request->prestador->id,
            ),
        ], 201);
    }

    private function animalAutorizado(string $codigo, Prestador $prestador): Animal
    {
        return $this->animalAlcancado($codigo, $prestador);
    }
}
