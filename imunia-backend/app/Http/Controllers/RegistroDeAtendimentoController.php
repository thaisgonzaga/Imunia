<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\RegistrarAtendimentoRequest;
use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Prestador;
use App\Models\User;
use App\Services\RegistroDeAtendimentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * V08 — registrar atendimento (RF31, RF32, RF34).
 *
 * Quatro verbos, e a divisão entre eles é o desenho da tela:
 *
 * - `show` abre o formulário e **grava** a linha do livro de acessos quando o
 *   animal tem registro de outro prestador (RN49), porque a tela conta o peso
 *   da consulta anterior e o retorno em aberto, que podem ser alheios;
 * - `anexar` recebe **um** arquivo por vez, antes da confirmação. É o que dá
 *   progresso por arquivo e recusa imediata por formato ou tamanho (RN28), e é
 *   o que permite ao erro de gravação prometer que o anexo já enviado não
 *   precisa subir de novo;
 * - `descartarAnexo` desfaz o envio enquanto ele ainda é rascunho;
 * - `store` grava o prontuário e vincula os anexos a ele.
 *
 * Não há verbo de alteração nem de exclusão de atendimento, e não é omissão:
 * RN26 torna o registro imutável, e a correção é a retificação de V09.
 */
class RegistroDeAtendimentoController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly RegistroDeAtendimentoService $registro) {}

    public function show(Request $request, string $codigo): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);
        $this->crmvExigido($profissional, $prestador);

        $animal = $this->animalAutorizado($codigo, $prestador);

        return response()->json([
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'vinculos' => $this->vinculosDe($profissional),
            ...$this->registro->montar($profissional, $prestador, $animal),
        ]);
    }

    /**
     * RN28 — o formato e o tamanho são conferidos aqui, no ato do envio, e a
     * recusa nomeia o arquivo e explica a razão. A conferência é do **conteúdo**
     * (`mimetypes`), não da extensão: um `.pdf` que é outra coisa por dentro não
     * entra no prontuário por ter o nome certo.
     */
    public function anexar(Request $request, string $codigo): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);
        $this->crmvExigido($profissional, $prestador);

        $this->animalAutorizado($codigo, $prestador);

        $nome = $this->nomeInformado($request);
        // Sem espaço depois da vírgula: a lista é lida como parâmetro da regra,
        // e " image/jpeg" não é formato algum.
        $formatos = implode(',', AnexoAtendimento::MIMES_ACEITOS);
        $megabytes = (int) (AnexoAtendimento::TAMANHO_MAXIMO_KB / 1024);

        Validator::make(
            $request->all(),
            [
                'arquivo' => [
                    'required',
                    'file',
                    "mimetypes:{$formatos}",
                    'max:'.AnexoAtendimento::TAMANHO_MAXIMO_KB,
                ],
            ],
            [
                'arquivo.required' => 'Escolha um arquivo para anexar.',
                'arquivo.mimetypes' => "{$nome} não foi anexado. O Imunia aceita PDF, JPG e PNG, porque o anexo "
                    .'precisa ser um documento fechado, que não muda depois de enviado. Exporte o arquivo como PDF '
                    .'e anexe novamente.',
                'arquivo.max' => "{$nome} não foi anexado: o limite é de {$megabytes} MB por arquivo. "
                    .'Reduza a resolução da imagem ou divida o documento antes de enviar.',
            ],
        )->validate();

        return response()->json(
            $this->registro->guardarRascunhoDeAnexo($profissional, $request->file('arquivo')),
            201,
        );
    }

    public function descartarAnexo(Request $request, string $codigo, string $token): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);
        $this->crmvExigido($profissional, $prestador);

        $this->animalAutorizado($codigo, $prestador);

        abort_if(
            ! $this->registro->descartarRascunhoDeAnexo($profissional, $token),
            404,
            'Este arquivo já não estava aqui.',
        );

        return response()->json(['descartado' => true]);
    }

    public function store(RegistrarAtendimentoRequest $request): JsonResponse
    {
        /** @var User $profissional */
        $profissional = $request->profissional;
        /** @var Prestador $prestador */
        $prestador = $request->prestador;
        /** @var Animal $animal */
        $animal = $request->animal;

        $atendimento = $this->registro->registrar($profissional, $prestador, $animal, $request->validated());

        return response()->json([
            'id' => $atendimento->id,
            'titulo' => $atendimento->titulo,
            'atendido_em' => $atendimento->atendido_em->toIso8601String(),
            'anexos' => $atendimento->anexos()->count(),
            'retorno' => $atendimento->retorno_em === null ? null : [
                'em' => $atendimento->retorno_em->toDateString(),
                'finalidade' => $atendimento->retorno_finalidade,
            ],

            // O briefing manda o sucesso abrir "T08 do atendimento criado", e
            // T08 é a tela do **tutor**: a rota que a serve exige tutor
            // autenticado e responde 403 a quem acaba de escrever o prontuário.
            // A leitura equivalente no ambiente clínico é a aba Histórico da
            // ficha, que é onde o registro passa a existir para quem o criou —
            // e a tela de detalhe do veterinário é fatia de V09.
            'destino' => sprintf(
                '/clinica/animais/%s?aba=historico&novo=%d&prestador=%d',
                $animal->codigo,
                $atendimento->id,
                $prestador->id,
            ),
        ], 201);
    }

    /**
     * RN12 — código inexistente e animal fora do âmbito respondem coisas
     * diferentes de propósito: o primeiro é 404, o segundo é 403 que nomeia o
     * caminho de V10.
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
            'Este animal tem óbito registrado. Não há novo atendimento a lançar.',
        );

        return $animal;
    }

    /**
     * O nome que o arquivo trazia, para que a recusa fale do arquivo que o
     * profissional escolheu, e não de "o arquivo". Vem do cliente e é tratado
     * como tal: entra na mensagem sem caminho e cortado no comprimento.
     */
    private function nomeInformado(Request $request): string
    {
        $arquivo = $request->file('arquivo');

        if (! is_object($arquivo) || ! method_exists($arquivo, 'getClientOriginalName')) {
            return 'O arquivo';
        }

        return Str::limit(basename($arquivo->getClientOriginalName()), 60);
    }
}
