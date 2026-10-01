<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAnimalRequest;
use App\Models\Animal;
use App\Models\Tutor;
use App\Models\User;
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
     * T04 — perfil do animal (RF16, RF17, RF19).
     */
    public function show(Request $request, string $codigo): JsonResponse
    {
        $animal = $this->animalDoTutor($request, $codigo);

        return response()->json($animal->paraPerfil());
    }

    /**
     * T04a — editar a identificação do animal (RF16).
     *
     * O animal já foi resolvido pela FormRequest, que precisava dele para saber
     * quais campos aceitar: a espécie trava no primeiro registro clínico
     * (RF19d), e sexo e nascimento deixam de ser do tutor quando o veterinário
     * caracteriza (RF19b). Por isso não há `$codigo` aqui.
     */
    public function update(UpdateAnimalRequest $request): JsonResponse
    {
        /** @var Animal $animal */
        $animal = $request->animal;

        $animal->update([
            'nome' => $request->validated('nome'),
            'especie' => $request->validated('especie'),

            // Caracterizado o animal, estes dois saem do alcance do tutor —
            // inclusive do `update`, e não só da validação: escrevê-los com o
            // que a tela mandou rebaixaria a confirmação do veterinário a
            // declaração de novo (RN14).
            ...($animal->preliminar() ? [
                'sexo' => $request->validated('sexo'),
                'nascimento_em' => $request->nascimentoEm(),
            ] : []),
        ]);

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
     * RN20 — manter a fotografia a qualquer tempo inclui retirá-la: o tutor que
     * pôs a foto errada não fica com ela até conseguir tirar outra.
     *
     * Responde igual quando não havia foto alguma. O que o tutor pediu — que
     * não haja foto — já vale, e inventar um 404 aqui faria a tela explicar um
     * erro que não houve.
     */
    public function removerFoto(Request $request, string $codigo): JsonResponse
    {
        $animal = $this->animalDoTutor($request, $codigo);

        $anterior = $animal->foto_caminho;

        if ($anterior !== null) {
            $animal->update(['foto_caminho' => null]);

            // Some do disco no mesmo ato, como a substituída: a foto não é
            // registro de nada (RN20), e guardá-la depois de o tutor mandar
            // apagá-la seria conservar o que ele pediu para não existir.
            Storage::disk(Animal::DISCO_DA_FOTO)->delete($anterior);
        }

        return response()->json(['foto_url' => null]);
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
