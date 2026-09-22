<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnimalRequest;
use App\Models\Animal;
use App\Models\Tutor;
use App\Models\User;
use App\Support\NomeSemelhante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AnimalController extends Controller
{
    /**
     * T02 — relação de animais do tutor (RF16). O painel (T01) já mostra os
     * mesmos animais; esta tela é o destino de quem quer só essa lista, sem o
     * restante do painel, e a porta de entrada para o cadastro de um novo.
     */
    public function index(Request $request): JsonResponse
    {
        $tutor = $this->tutorAutenticado($request);

        $animais = $tutor->animais()->orderBy('nome')->get();

        return response()->json([
            'animais' => $animais->map(fn (Animal $animal) => $animal->paraListagem())->all(),
        ]);
    }

    /**
     * T03 — cadastrar animal (RF16, RF17, RF20a).
     *
     * O código único nasce com o cadastro, no `creating` do modelo, e não aqui:
     * RF17c vale para toda origem de cadastro, e amarrá-lo a este verbo faria a
     * garantia depender de qual porta o animal usou para entrar.
     */
    public function store(StoreAnimalRequest $request): JsonResponse
    {
        /** @var Tutor $tutor */
        $tutor = $request->tutor;

        $duplicado = $this->duplicidadeProvavel(
            $tutor,
            $request->validated('nome'),
            $request->validated('especie'),
        );

        // RF20a — o alerta precede a confirmação e identifica o cadastro
        // possivelmente equivalente. Não é recusa: o segundo envio, já ciente,
        // cadastra. A prevenção importa mais do que a correção posterior
        // porque a fusão de dois cadastros com registro clínico em ambos não é
        // oferecida (limitação declarada de RF20).
        if ($duplicado !== null && ! $request->boolean('confirmar_duplicidade')) {
            return response()->json([
                'message' => 'Você já tem um cadastro parecido com este.',
                'duplicado' => $duplicado->paraListagem(),
            ], 409);
        }

        $animal = $tutor->animais()->create([
            'nome' => $request->validated('nome'),
            'especie' => $request->validated('especie'),
            'sexo' => $request->validated('sexo'),
            'nascimento_em' => $request->nascimentoEm(),

            // RN14 — o que o tutor declara é estimativa até que o veterinário a
            // confirme (RF19). Explícito, e não deixado ao padrão da coluna,
            // porque é esta linha que a resposta devolve à tela.
            'nascimento_exato' => false,
        ]);

        return response()->json($animal->paraPerfil(), 201);
    }

    /**
     * T04 — perfil do animal (RF16, RF17, RF19).
     */
    public function show(Request $request, string $codigo): JsonResponse
    {
        $animal = $this->animalDoTutor($request, $codigo);

        return response()->json($animal->paraPerfil());
    }

    /**
     * RF16b, RN20 — a fotografia entra por rota própria, e não no corpo do
     * cadastro, por duas razões: o mesmo verbo serve à substituição a qualquer
     * tempo, e o cadastro pode prosseguir quando o envio falha. Perder a foto
     * de quem está numa conexão ruim é aceitável; perder o cadastro inteiro
     * junto com ela não é.
     */
    public function foto(Request $request, string $codigo): JsonResponse
    {
        $animal = $this->animalDoTutor($request, $codigo);

        // Sem espaço depois da vírgula: a lista é lida como parâmetro da regra,
        // e " image/png" não é formato algum.
        $formatos = implode(',', Animal::MIMES_DA_FOTO);
        $megabytes = (int) (Animal::TAMANHO_MAXIMO_DA_FOTO_KB / 1024);

        Validator::make(
            $request->all(),
            [
                'arquivo' => [
                    'required',
                    'file',
                    "mimetypes:{$formatos}",
                    'max:'.Animal::TAMANHO_MAXIMO_DA_FOTO_KB,
                ],
            ],
            [
                'arquivo.required' => 'Escolha uma imagem.',
                'arquivo.mimetypes' => 'A foto precisa ser JPG, PNG ou WebP.',
                'arquivo.max' => "A foto precisa ter até {$megabytes} MB. "
                    .'Tire a foto em resolução menor ou escolha outra imagem.',
            ],
        )->validate();

        $anterior = $animal->foto_caminho;

        $animal->update([
            'foto_caminho' => $request->file('arquivo')->store(
                Animal::PASTA_DA_FOTO,
                Animal::DISCO_DA_FOTO,
            ),
        ]);

        // A substituída sai do disco no mesmo ato: ela não é registro de nada
        // (RN20 a distingue do dado clínico, que RN22 manda conservar), e
        // guardá-la seria acumular imagem que nenhuma tela alcança.
        if ($anterior !== null) {
            Storage::disk(Animal::DISCO_DA_FOTO)->delete($anterior);
        }

        return response()->json(['foto_url' => $animal->fotoUrl()]);
    }

    /**
     * RF20a — "animal ativo, de mesma espécie e nome semelhante", do mesmo
     * tutor. A espécie entra na consulta e o nome fica para
     * `NomeSemelhante`, que compara o que o banco não sabe comparar.
     *
     * O animal com óbito registrado está fora: quem cadastra outro cão com o
     * nome do que morreu não está duplicando cadastro algum, e receber um
     * alerta nesse momento seria mais do que inútil.
     */
    private function duplicidadeProvavel(Tutor $tutor, string $nome, string $especie): ?Animal
    {
        return $tutor->animais()
            ->where('especie', $especie)
            ->whereNull('obito_em')
            ->get()
            ->first(fn (Animal $animal) => NomeSemelhante::entre($animal->nome, $nome));
    }

    private function tutorAutenticado(Request $request): Tutor
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        return $tutor;
    }

    /**
     * A busca parte sempre do tutor autenticado: um código que existe mas
     * pertence a outro tutor responde 404, igual a um código inexistente, para
     * não revelar que o cadastro existe (RN12).
     */
    private function animalDoTutor(Request $request, string $codigo): Animal
    {
        $animal = $this->tutorAutenticado($request)->animais()->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        return $animal;
    }
}
