<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Services\FichaClinicaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FichaClinicaController extends Controller
{
    use ResolvePrestadorAtivo;

    public function __construct(private readonly FichaClinicaService $ficha)
    {
    }

    /**
     * V06 — ficha clínica do animal (RF19, RF35, RF52).
     *
     * Ao contrário de T04, esta rota **não** responde 404 para o animal fora do
     * âmbito. A diferença é deliberada e é a materialização de P2: no ambiente
     * do tutor, o animal de outro tutor não existe, e dizer que existe já seria
     * informação; no ambiente clínico, o animal de outro prestador existe e o
     * profissional pode saber que existe — RF18a autoriza espécie, nome e
     * código, e nada mais. O que 404 esconderia aqui não é dado sensível: é o
     * caminho para pedir a autorização, que é justamente o que a tela precisa
     * oferecer.
     *
     * A resposta vem inteira em uma requisição porque o briefing exige abas que
     * trocam sem recarregar, e porque a gravação do acesso é condição da
     * exibição (RF52b): dividir a ficha em quatro rotas produziria quatro
     * momentos distintos de gravar o mesmo log.
     */
    public function show(Request $request, string $codigo): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $animal = Animal::where('codigo', $codigo)->first();

        // Código inexistente é 404 para todo mundo. Aqui não há o que proteger:
        // não existe cadastro cuja existência se pudesse revelar.
        abort_if($animal === null, 404, 'Animal não encontrado.');

        return response()->json([
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'vinculos' => $this->vinculosDe($profissional),
            ...$this->ficha->montar($profissional, $prestador, $animal),
        ]);
    }

    /**
     * RF32c — o mesmo arquivo de T08, servido pela rota do ambiente clínico,
     * porque o âmbito é outro: ali quem pede é o tutor titular; aqui, o
     * prestador sob autorização vigente. Duas portas, uma verificação em cada,
     * e nenhum endereço direto do armazenamento em nenhuma das duas.
     */
    public function anexo(Request $request, string $codigo, int $anexo): StreamedResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $animal = Animal::where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        $arquivo = $this->ficha->localizarAnexo($profissional, $prestador, $animal, $anexo);

        return Storage::disk(AnexoAtendimento::DISCO)->response(
            $arquivo->caminho,
            $arquivo->descricao,
            [
                'Content-Type' => $arquivo->mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
