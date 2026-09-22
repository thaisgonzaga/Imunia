<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\RegistrarVacinacaoRequest;
use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\User;
use App\Services\CalendarioVacinalService;
use App\Services\RegistroDeVacinacaoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * V07 — registrar vacinação (RF25, RF26, RF27).
 *
 * Três verbos para duas operações, e a separação é deliberada:
 *
 * - `show` abre a tela e **grava** a linha do livro de acessos quando o animal
 *   tem registro de outro prestador (RN49), porque o painel conta ao
 *   profissional quando venceu a dose anterior, e essa data pode ser alheia;
 * - `previa` recalcula a cada campo alterado e **não grava nada**. Fosse o mesmo
 *   endereço de `show`, uma vacinação de noventa segundos deixaria uma dezena de
 *   linhas no livro que o tutor lê em T14, e RF53 o promete legível. Sendo GET
 *   puro, também não quebra com prefetch nem com o botão voltar;
 * - `store` grava a aplicação.
 *
 * Não há aqui verbo de alteração nem de exclusão, e não é omissão: RN26 torna o
 * registro clínico imutável, e a correção é a retificação vinculada de V09.
 */
class RegistroDeVacinacaoController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(
        private readonly RegistroDeVacinacaoService $registro,
        private readonly CalendarioVacinalService $calendario,
    ) {}

    public function show(Request $request, string $codigo): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);
        $this->crmvExigido($profissional, $prestador);

        $animal = $this->animalAutorizado($codigo, $prestador);

        return response()->json([
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'vinculos' => $this->vinculosDe($profissional),
            ...$this->registro->montar($profissional, $prestador, $animal, $request->string('imunobiologico')->value() ?: null),
        ]);
    }

    /**
     * O painel de cálculo ao vivo. Devolve só o que muda quando um campo muda —
     * sugestões, ordem da dose, cálculo e alertas —, e não o catálogo inteiro:
     * RNF15 mede noventa segundos, e trafegar o catálogo a cada tecla seria peso
     * morto no caminho crítico.
     */
    public function previa(Request $request, string $codigo): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);
        $this->crmvExigido($profissional, $prestador);

        $animal = $this->animalAutorizado($codigo, $prestador);

        $imunobiologico = Imunobiologico::query()
            ->where('chave', $request->string('imunobiologico')->value())
            ->paraEspecieDe($animal, $prestador)
            ->first();

        return response()->json($this->registro->prever(
            $animal,
            $prestador,
            $imunobiologico,
            $this->momento($request->string('aplicado_em')->value()),
            $request->filled('ordem_dose') ? $request->integer('ordem_dose') : null,
        ));
    }

    public function store(RegistrarVacinacaoRequest $request): JsonResponse
    {
        /** @var User $profissional */
        $profissional = $request->profissional;
        /** @var Prestador $prestador */
        $prestador = $request->prestador;
        /** @var Animal $animal */
        $animal = $request->animal;

        $vacinacao = $this->registro->registrar($profissional, $prestador, $animal, $request->validated());

        // O resumo que substitui o painel de cálculo, sem troca de página: o
        // selo do registro recém-criado, com a mesma serialização que a carteira
        // do tutor usa, e a situação do animal depois dele.
        return response()->json(
            $this->calendario->detalheAplicacao($animal, $vacinacao) + [
                'id' => $vacinacao->id,
                'situacao_animal' => $this->calendario->situacaoGeral($animal),
                'destino_carteira' => sprintf(
                    '/clinica/animais/%s?aba=carteira&novo=%d&prestador=%d',
                    $animal->codigo,
                    $vacinacao->id,
                    $prestador->id,
                ),
            ],
            201,
        );
    }

    /**
     * RN12 — código inexistente e animal fora do âmbito respondem coisas
     * diferentes de propósito: o primeiro é 404, o segundo é 403 que nomeia o
     * caminho de V10. Negar a existência de quem existe mandaria o profissional
     * procurar de novo o que ele já encontrou.
     */
    private function animalAutorizado(string $codigo, Prestador $prestador): Animal
    {
        $animal = Animal::query()->with('tutor')->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        abort_if(
            ! $animal->autorizacoes()->where('prestador_id', $prestador->id)->vigente()->exists(),
            403,
            'Este animal não está sob autorização vigente do tutor. Solicite o acesso antes de registrar.',
        );

        abort_if(
            $animal->inativo(),
            403,
            'Este animal tem óbito registrado. Não há nova aplicação a lançar.',
        );

        return $animal;
    }

    /**
     * A data que a tela mandou, quando mandou uma legível. A prévia não valida:
     * ela é consultada enquanto o campo ainda está sendo digitado, e recusar
     * "05/08/20" com erro seria acusar de errado quem está no meio da frase.
     */
    private function momento(?string $valor): ?Carbon
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
