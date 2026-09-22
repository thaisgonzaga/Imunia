<?php

namespace App\Http\Controllers;

use App\Http\Requests\SalvarParametrosProtocoloRequest;
use App\Http\Requests\SimularCalendarioRequest;
use App\Http\Requests\StoreVersaoProtocoloRequest;
use App\Models\Imunobiologico;
use App\Models\ProtocoloVacinal;
use App\Models\User;
use App\Models\VersaoProtocolo;
use App\Services\PublicacaoDeProtocoloService;
use App\Services\SimuladorDeProtocoloService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * X02 — versões do cálculo de calendário (RF24). É por aqui que uma revisão de
 * diretriz entra no sistema: publica-se uma versão nova, e nenhuma linha de
 * código muda.
 *
 * A tela é uma só e troca de versão e de imunobiológico sem recarregar, então a
 * leitura vem inteira — versões, parâmetros de cada uma e o catálogo. São
 * dezenas de linhas, não milhares.
 */
class ProtocolosVacinaisController extends Controller
{
    public function __construct(
        private PublicacaoDeProtocoloService $publicacao,
        private SimuladorDeProtocoloService $simulador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->exigirAdminPlataforma($request);

        $versoes = VersaoProtocolo::query()
            ->with(['protocolos.imunobiologico'])
            ->orderByRaw("field(situacao, 'rascunho', 'vigente', 'encerrada')")
            ->orderByDesc('publicado_em')
            ->get();

        return response()->json([
            'versoes' => $versoes->map(fn (VersaoProtocolo $versao) => $this->representarVersao($versao)),
            'imunobiologicos' => Imunobiologico::query()
                ->where('ativo', true)
                // Só o acervo da plataforma, e isto é correção de cálculo e não
                // de interface: gravar parâmetros de um item próprio de clínica
                // dentro da versão vigente lhe daria duas linhas candidatas a
                // `protocoloVigente()` — a sem versão, de A04, e a versionada —,
                // e o desempate por id decidiria por acaso qual explica a data
                // do lembrete daquele tutor.
                ->whereNull('prestador_id')
                ->orderBy('nome_comercial')
                ->get()
                ->map(fn (Imunobiologico $item) => [
                    'id' => $item->id,
                    'nome_comercial' => $item->nome_comercial,
                    'especie_destino' => $item->especie_destino,
                ]),
            'casos' => collect(SimuladorDeProtocoloService::CASOS)
                ->map(fn (string $rotulo, string $chave) => ['chave' => $chave, 'rotulo' => $rotulo])
                ->values(),
        ]);
    }

    /**
     * A versão nova nasce cópia da vigente (RF24a): a revisão de uma diretriz
     * muda um parâmetro ou outro, e começar do zero convidaria ao esquecimento.
     */
    public function store(StoreVersaoProtocoloRequest $request): JsonResponse
    {
        $rascunho = $this->publicacao->criarRascunhoAPartirDaVigente(
            $request->validated('rotulo'),
            $request->validated('base'),
        );

        return response()->json(['versao' => $this->representarVersao($rascunho->fresh(['protocolos.imunobiologico']))], 201);
    }

    /**
     * Grava os parâmetros de um imunobiológico dentro do rascunho — criando a
     * linha quando a versão ainda não tinha nenhuma para ele.
     */
    public function salvarParametros(SalvarParametrosProtocoloRequest $request, VersaoProtocolo $versao): JsonResponse
    {
        $this->exigirRascunho($versao);

        $dados = $request->validated();

        $protocolo = ProtocoloVacinal::updateOrCreate(
            [
                'versao_protocolo_id' => $versao->id,
                'imunobiologico_id' => $dados['imunobiologico_id'],
            ],
            $dados,
        );

        return response()->json([
            'parametros' => $this->representarParametros($protocolo),
            // As contradições viajam junto porque é a gravação que as cria ou
            // desfaz, e é o botão "Publicar" que depende delas.
            'incoerencias' => $this->publicacao->incoerencias($versao->fresh(['protocolos.imunobiologico'])),
        ]);
    }

    /**
     * RN32 — a publicação troca a versão dos cálculos seguintes e não toca em
     * data alguma já emitida. Não há aqui, nem no serviço, caminho que
     * recalcule registro anterior.
     */
    public function publicar(Request $request, VersaoProtocolo $versao): JsonResponse
    {
        $this->exigirAdminPlataforma($request);

        $publicada = $this->publicacao->publicar($versao);

        return response()->json(['versao' => $this->representarVersao($publicada->load('protocolos.imunobiologico'))]);
    }

    public function descartar(Request $request, VersaoProtocolo $versao): JsonResponse
    {
        $this->exigirAdminPlataforma($request);

        $this->publicacao->descartar($versao);

        return response()->json(['descartada' => true]);
    }

    /**
     * O simulador não grava nada, e por isso responde a POST sem criar
     * recurso algum: o verbo está aqui pelo tamanho das entradas, não por
     * efeito colateral.
     */
    public function simular(SimularCalendarioRequest $request): JsonResponse
    {
        $protocolo = ProtocoloVacinal::with(['imunobiologico', 'versaoProtocolo'])
            ->where('versao_protocolo_id', $request->validated('versao_id'))
            ->where('imunobiologico_id', $request->validated('imunobiologico_id'))
            ->first();

        abort_if(
            $protocolo === null,
            422,
            'Esta versão não tem parâmetros para este imunobiológico. Defina-os antes de simular.',
        );

        $caso = $request->validated('caso');

        if ($caso !== null) {
            $entradas = $this->simulador->casos($protocolo)[$caso];
            $nascimento = $entradas['nascimento_em'] !== null ? Carbon::parse($entradas['nascimento_em']) : null;
            $doses = collect($entradas['doses'])->map(fn (string $data) => Carbon::parse($data));
        } else {
            $nascimento = $request->validated('nascimento_em') !== null
                ? Carbon::parse($request->validated('nascimento_em'))
                : null;
            $doses = collect($request->validated('doses') ?? [])->map(fn (string $data) => Carbon::parse($data));
        }

        return response()->json([
            'simulacao' => [
                ...$this->simulador->simular($protocolo, $nascimento, $doses),
                'caso' => $caso,
                'versao' => [
                    'id' => $protocolo->versaoProtocolo->id,
                    'rotulo' => $protocolo->versaoProtocolo->rotulo,
                    'situacao' => $protocolo->versaoProtocolo->situacao,
                ],
                'imunobiologico' => [
                    'id' => $protocolo->imunobiologico->id,
                    'nome_comercial' => $protocolo->imunobiologico->nome_comercial,
                ],
            ],
        ]);
    }

    private function exigirAdminPlataforma(Request $request): void
    {
        /** @var User $usuario */
        $usuario = $request->user();

        abort_if(! $usuario->admin_plataforma, 403, 'Esta área é da administração da plataforma.');
    }

    private function exigirRascunho(VersaoProtocolo $versao): void
    {
        abort_if(
            ! $versao->rascunho(),
            422,
            'Versão publicada não é editada. Para mudar um parâmetro, crie uma versão nova a partir dela.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function representarVersao(VersaoProtocolo $versao): array
    {
        return [
            'id' => $versao->id,
            'rotulo' => $versao->rotulo,
            'situacao' => $versao->situacao,
            'base' => $versao->base,
            'publicado_em' => $versao->publicado_em?->toDateString(),
            'encerrado_em' => $versao->encerrado_em?->toDateString(),
            // "Em edição desde" — só o rascunho a exibe, e é a data em que ele
            // foi aberto, não a da última tecla digitada.
            'aberta_em' => $versao->created_at?->toDateString(),
            'calculos' => $versao->calculosRealizados(),
            'incoerencias' => $this->publicacao->incoerencias($versao),
            'parametros' => $versao->protocolos->map(fn (ProtocoloVacinal $protocolo) => $this->representarParametros($protocolo)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function representarParametros(ProtocoloVacinal $protocolo): array
    {
        return [
            'id' => $protocolo->id,
            'imunobiologico_id' => $protocolo->imunobiologico_id,
            'numero_doses_serie_primaria' => $protocolo->numero_doses_serie_primaria,
            'intervalo_minimo_dias' => $protocolo->intervalo_minimo_dias,
            'intervalo_maximo_dias' => $protocolo->intervalo_maximo_dias,
            'intervalo_previsto_dias' => $protocolo->intervaloPrevistoDias(),
            'idade_minima_primeira_dose_semanas' => $protocolo->idade_minima_primeira_dose_semanas,
            'idade_minima_dose_final_semanas' => $protocolo->idade_minima_dose_final_semanas,
            'reforco_inicial_meses' => $protocolo->reforco_inicial_meses,
            'periodicidade_revacinacao_meses' => $protocolo->periodicidade_revacinacao_meses,
            'limite_atraso_dias' => $protocolo->limite_atraso_dias,
            'conduta_apos_limite' => $protocolo->conduta_apos_limite,
            'doses_adulto_sem_historico' => $protocolo->doses_adulto_sem_historico,
        ];
    }
}
