<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\User;
use App\Services\CalendarioVacinalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PainelTutorController extends Controller
{
    public function __construct(private readonly CalendarioVacinalService $calendario)
    {
    }

    /**
     * T01 — painel do tutor (RF50). Uma requisição só, e não uma por bloco: a
     * tela abre no celular de quem chega pelo lembrete, e RNF03 conta o tempo
     * até a primeira dobra.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $tutor = $usuario->tutor;

        // O ambiente do tutor é do tutor: quem não tem esse cadastro não tem
        // painel — nem vazio. O veterinário e o administrador do prestador têm
        // painéis próprios, com âmbito próprio (§3.2).
        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        $animais = $tutor->animais()->orderBy('nome')->get();

        $pendencias = $animais
            ->flatMap(fn (Animal $animal) => collect($this->calendario->montarCarteira($animal)['grupos'])
                ->filter(fn (array $grupo) => in_array($grupo['situacao']['tipo'], ['atrasada', 'proxima'], true))
                ->map(fn (array $grupo) => [
                    'animal' => [
                        'codigo' => $animal->codigo,
                        'nome' => $animal->nome,
                        'especie' => $animal->especie,
                        'foto_url' => $animal->fotoUrl(),
                    ],
                    'imunobiologico' => $grupo['imunobiologico']['nome'],
                    'prevista_para' => $grupo['proxima_dose']['prevista_para'],
                    'situacao' => $grupo['situacao'],
                ]))
            ->sortBy('prevista_para')
            ->values();

        // Retorno programado (RF34) é fatia futura (RF31, atendimento): o
        // formato já é o definitivo, e esta lista fica vazia até que aquela
        // fatia exista, sem que T01 precise mudar quando ela chegar.
        $retornos = [];

        // Situação consolidada dos animais do tutor. Enquanto nenhum tiver
        // vacinação registrada, o painel não afirma que estão em dia: RF50
        // pede a situação, e "ainda não sei" é situação distinta de "em dia"
        // — afirmar a segunda no lugar da primeira seria, num sistema de
        // saúde, informação errada, não simplificação.
        $situacoes = $animais->map(fn (Animal $animal) => $this->calendario->situacaoGeral($animal));
        $situacaoGeral = match (true) {
            $pendencias->isNotEmpty() => 'com_pendencias',
            $situacoes->isNotEmpty() && $situacoes->doesntContain(null) => 'em_dia',
            default => 'sem_registros',
        };

        return response()->json([
            'tutor' => [
                'nome' => $tutor->nome,
                'email' => $usuario->email,
                // RF05 — a tarja de confirmação pendente é desenhada a partir
                // daqui, e some sozinha quando o endereço é confirmado.
                'email_verificado' => $usuario->hasVerifiedEmail(),
            ],
            'animais' => $animais->map(fn (Animal $animal) => $animal->paraListagem())->all(),
            'pendencias' => $pendencias->all(),
            'retornos' => $retornos,
            'situacao_geral' => $situacaoGeral,
        ]);
    }
}
