<?php

namespace App\Http\Controllers;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\User;
use App\Services\AtendimentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AtendimentoController extends Controller
{
    public function __construct(private readonly AtendimentoService $atendimentos)
    {
    }

    /**
     * T08 — detalhe do atendimento (RF31, RF32, RF33). Mesma checagem de âmbito
     * de T04 a T07: a busca parte sempre do tutor autenticado, e um registro que
     * existe mas é de outro animal — ou de animal de outro tutor — responde 404
     * igual a um id inexistente (RN12).
     *
     * A resposta é de leitura pura. Não há aqui, nem em rota alguma do ambiente
     * do tutor, caminho de escrita sobre o registro: a imutabilidade de RN26 não
     * é um bloqueio a ser vencido, é a ausência da operação.
     *
     * RF52 não incide, pela mesma razão de T07: o registro de acesso em log é do
     * prestador que consulta histórico produzido por outro (RN49), e esta rota é
     * o tutor vendo o próprio animal. A visão do veterinário — com o aviso que
     * precede o conteúdo, a ação "Retificar" do autor (RN27) e a densidade
     * maior — é a fatia de V06 e V09.
     */
    public function show(Request $request, string $codigo, int $atendimento): JsonResponse
    {
        $animal = $this->animalDoTutor($request, $codigo);
        $registro = $this->atendimentoDoAnimal($animal, $atendimento);

        return response()->json([
            'animal' => $animal->paraListagem(),
            ...$this->atendimentos->montarDetalhe($animal, $registro),
        ]);
    }

    /**
     * RF32c — o arquivo é servido por esta rota, que confere a autorização a
     * cada pedido, e nunca por endereço direto do armazenamento. O disco é
     * privado justamente para que não exista o segundo caminho: uma URL pública
     * assinada uma vez continuaria valendo depois de revogado o acesso.
     */
    public function anexo(Request $request, string $codigo, int $atendimento, int $anexo): StreamedResponse
    {
        $animal = $this->animalDoTutor($request, $codigo);
        $registro = $this->atendimentoDoAnimal($animal, $atendimento);

        /** @var AnexoAtendimento|null $arquivo */
        $arquivo = $registro->anexos()->find($anexo);

        abort_if($arquivo === null, 404, 'Anexo não encontrado.');
        abort_if(! $arquivo->disponivel(), 404, 'Este anexo não está disponível agora.');

        // O tipo declarado é o que foi conferido no envio (RN28), e `nosniff`
        // impede que o navegador o reinterprete a partir do conteúdo — um
        // arquivo enviado como imagem não vira documento executável no caminho.
        // O `Content-Disposition` fica por conta do próprio Laravel, que sabe
        // escapar um nome com acento, aspas ou vírgula sem quebrar o cabeçalho.
        return Storage::disk(AnexoAtendimento::DISCO)->response(
            $arquivo->caminho,
            $arquivo->descricao,
            [
                'Content-Type' => $arquivo->mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function animalDoTutor(Request $request, string $codigo): Animal
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        $animal = $tutor->animais()->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        return $animal;
    }

    private function atendimentoDoAnimal(Animal $animal, int $atendimento): Atendimento
    {
        $registro = $animal->atendimentos()->find($atendimento);

        abort_if($registro === null, 404, 'Atendimento não encontrado.');

        return $registro;
    }
}
