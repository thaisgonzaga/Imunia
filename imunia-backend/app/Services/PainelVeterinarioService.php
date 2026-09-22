<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Vacinacao;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Painel do veterinário (RF48, V01). Responde à pergunta com que o profissional
 * abre o sistema: o que aconteceu aqui nos últimos dias, e o que ficou pendente?
 *
 * Dois âmbitos se somam, e nenhum dos dois é dispensável:
 *
 * - **prestador ativo** (RF48a) — o painel nunca mistura estabelecimentos. Um
 *   mesmo veterinário atende em vários, e o que ele registrou em um não é
 *   assunto do outro (decisoes.md §4.2);
 * - **autorização vigente** (RN48) — só entram animais que o tutor autorizou o
 *   prestador a acompanhar. Sem nenhuma autorização não há painel: não é falha,
 *   é a regra funcionando.
 *
 * Regra de negócio vive aqui, e não no controlador (decisoes.md §5.2).
 */
class PainelVeterinarioService
{
    /**
     * RF48b — o intervalo é ajustável pelo usuário. Três opções, e não um campo
     * livre de datas: a pergunta do painel é "e agora?", não "e em março?" —
     * essa é a de V02, que tem filtro de período próprio.
     *
     * @var list<int>
     */
    public const INTERVALOS_EM_DIAS = [7, 30, 90];

    /** RF48 fala em trinta dias; os outros dois intervalos são o ajuste de RF48b. */
    public const INTERVALO_PADRAO_DIAS = 30;

    /** Linhas por página da tabela de animais atendidos, conforme o desenho de V01. */
    private const ANIMAIS_POR_PAGINA = 5;

    /** Itens dos blocos da coluna da direita, antes do "Ver todas" que leva a V02. */
    private const ITENS_POR_BLOCO = 5;

    public function __construct(private readonly CalendarioVacinalService $calendario)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function montar(User $veterinario, Prestador $prestador, int $dias, int $pagina): array
    {
        $desde = now()->subDays($dias);

        // Uma consulta só para o âmbito inteiro, e daqui em diante tudo se
        // restringe a estes animais. É o filtro que, esquecido em um único
        // lugar, transforma o painel em vazamento de histórico alheio (RN48).
        $autorizados = Animal::query()->sobAutorizacaoVigenteDe($prestador)->with('tutor')->get();

        // A carteira de cada animal é cara — uma consulta com quatro relações —
        // e é pedida duas vezes: pelas pendências e pela coluna de situação da
        // tabela. Montada uma vez por animal e guardada, para que a segunda
        // pergunta não repita a primeira. A rechamada em escala é V02, que
        // responde por consulta e não por laço (RNF03).
        $carteiras = $autorizados->mapWithKeys(
            fn (Animal $animal) => [$animal->id => $this->calendario->montarCarteira($animal)],
        );

        $pendencias = $this->pendencias($autorizados, $carteiras, $dias);
        $retornos = $this->retornos($prestador, $autorizados, $dias);

        return [
            'profissional' => [
                'nome' => $veterinario->name,
                'crmv' => $veterinario->crmvEm($prestador),
            ],
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'intervalo' => ['dias' => $dias, 'opcoes' => self::INTERVALOS_EM_DIAS],
            'estado' => $this->estado($prestador, $autorizados),
            'indicadores' => $this->indicadores($veterinario, $prestador, $autorizados, $pendencias, $retornos, $desde, $dias),
            'animais_atendidos' => $this->animaisAtendidos($prestador, $autorizados, $carteiras, $desde, $pagina),
            'pendencias' => $pendencias->take(self::ITENS_POR_BLOCO)->values()->all(),
            'retornos' => $retornos->take(self::ITENS_POR_BLOCO)->values()->all(),
        ];
    }

    /**
     * Os três estados de tela que não são o normal (§8.3 do briefing). A ordem
     * importa: quem nunca registrou nada precisa dos três passos iniciais, e não
     * da explicação sobre autorização — que só faz sentido para quem já trabalha
     * no sistema e viu o painel esvaziar.
     *
     * @param Collection<int, Animal> $autorizados
     */
    private function estado(Prestador $prestador, Collection $autorizados): string
    {
        $semRegistroAlgum = ! Atendimento::where('prestador_id', $prestador->id)->exists()
            && ! Vacinacao::where('prestador_id', $prestador->id)->where('origem', 'profissional')->exists();

        return match (true) {
            $semRegistroAlgum => 'primeiro_acesso',
            $autorizados->isEmpty() => 'sem_autorizacoes',
            default => 'normal',
        };
    }

    /**
     * Os quatro números do topo. São números, e não gráficos: cada um responde a
     * uma pergunta declarada e leva à lista que a detalha.
     *
     * @param Collection<int, Animal> $autorizados
     * @param Collection<int, array<string, mixed>> $pendencias
     * @param Collection<int, array<string, mixed>> $retornos
     * @return array<string, mixed>
     */
    private function indicadores(
        User $veterinario,
        Prestador $prestador,
        Collection $autorizados,
        Collection $pendencias,
        Collection $retornos,
        CarbonInterface $desde,
        int $dias,
    ): array {
        $ids = $autorizados->modelKeys();

        // "Registrados por você": RF48 fala dos animais atendidos pelo
        // profissional, e este é o único indicador pessoal do painel. Os demais
        // são do prestador ativo, porque é dele o âmbito de RF48a — e porque
        // `vacinacoes` guarda o aplicador como nome e CRMV (RF25), sem chave
        // para o usuário, de modo que não haveria como somar "por você" ali sem
        // comparar cadeias de caracteres.
        $atendimentos = Atendimento::whereIn('animal_id', $ids)
            ->where('prestador_id', $prestador->id)
            ->where('profissional_user_id', $veterinario->id)
            ->where('atendido_em', '>=', $desde)
            ->count();

        $vacinas = Vacinacao::whereIn('animal_id', $ids)
            ->where('prestador_id', $prestador->id)
            ->where('origem', 'profissional')
            ->where('aplicado_em', '>=', $desde)
            ->count();

        // Contagem de animais, não de doses: a rechamada liga para tutores, e
        // dois reforços vencidos do mesmo cão são um telefonema, não dois.
        $atrasados = $pendencias->where('situacao.tipo', 'atrasada')
            ->pluck('animal.codigo')->unique()->count();
        $aVencer = $pendencias->where('situacao.tipo', '!=', 'atrasada')
            ->pluck('animal.codigo')->unique()->count();

        return [
            'atendimentos' => $atendimentos,
            'vacinas_aplicadas' => $vacinas,
            'doses_vencidas' => ['animais' => $atrasados, 'a_vencer' => $aVencer],
            'retornos_previstos' => $retornos->count(),
            'dias' => $dias,
        ];
    }

    /**
     * Doses vencidas e a vencer dentro do intervalo escolhido, do animal mais
     * urgente para o menos. Ordenar pela data prevista já põe as atrasadas na
     * frente, da mais antiga para a mais recente, sem precisar ordenar por
     * situação antes.
     *
     * @param Collection<int, Animal> $autorizados
     * @param Collection<int, array<string, mixed>> $carteiras
     * @return Collection<int, array<string, mixed>>
     */
    private function pendencias(Collection $autorizados, Collection $carteiras, int $dias): Collection
    {
        $limite = today()->addDays($dias)->toDateString();

        return $autorizados
            ->flatMap(fn (Animal $animal) => collect($carteiras[$animal->id]['proximas_doses'])
                ->filter(fn (array $dose) => $dose['prevista_para'] <= $limite)
                ->map(fn (array $dose) => [
                    'animal' => [
                        'codigo' => $animal->codigo,
                        'nome' => $animal->nome,
                        'especie' => $animal->especie,
                    ],
                    'imunobiologico' => $dose['imunobiologico'],
                    'prevista_para' => $dose['prevista_para'],
                    'situacao' => $dose['situacao'],
                ]))
            ->sortBy('prevista_para')
            ->values();
    }

    /**
     * RF34 — retornos programados que caem dentro do intervalo. Só os futuros:
     * o retorno que já passou ou foi cumprido, e aí saiu do painel por RF34c, ou
     * não foi, e aí é assunto da rechamada em V02, não do "o que vem por aí".
     *
     * @param Collection<int, Animal> $autorizados
     * @return Collection<int, array<string, mixed>>
     */
    private function retornos(Prestador $prestador, Collection $autorizados, int $dias): Collection
    {
        $animaisPorId = $autorizados->keyBy('id');

        return Atendimento::whereIn('animal_id', $animaisPorId->keys())
            ->where('prestador_id', $prestador->id)
            ->whereNotNull('retorno_em')
            ->whereBetween('retorno_em', [today(), today()->addDays($dias)])
            ->orderBy('retorno_em')
            ->get()
            ->map(fn (Atendimento $atendimento) => [
                'animal' => [
                    'codigo' => $animaisPorId[$atendimento->animal_id]->codigo,
                    'nome' => $animaisPorId[$atendimento->animal_id]->nome,
                ],
                'finalidade' => $atendimento->retorno_finalidade,
                'prevista_para' => $atendimento->retorno_em->toDateString(),
            ])
            ->values();
    }

    /**
     * A lista "Animais atendidos recentemente": quem passou pelo prestador ativo
     * dentro do intervalo, do registro mais recente para o mais antigo, com
     * acesso direto ao histórico (RF48).
     *
     * A última data vem de duas origens — atendimento (RF31) e aplicação de
     * vacina (RF25) —, resolvidas em duas consultas agregadas em vez de uma por
     * animal. A tela exibe qual das duas foi, porque "vacina" e "atendimento"
     * dizem coisas diferentes sobre o que o profissional vai encontrar na ficha.
     *
     * @param Collection<int, Animal> $autorizados
     * @param Collection<int, array<string, mixed>> $carteiras
     * @return array<string, mixed>
     */
    private function animaisAtendidos(
        Prestador $prestador,
        Collection $autorizados,
        Collection $carteiras,
        CarbonInterface $desde,
        int $pagina,
    ): array {
        $ids = $autorizados->modelKeys();

        $porAtendimento = Atendimento::whereIn('animal_id', $ids)
            ->where('prestador_id', $prestador->id)
            ->where('atendido_em', '>=', $desde)
            ->selectRaw('animal_id, MAX(atendido_em) as em')
            ->groupBy('animal_id')
            ->pluck('em', 'animal_id');

        $porVacina = Vacinacao::whereIn('animal_id', $ids)
            ->where('prestador_id', $prestador->id)
            ->where('origem', 'profissional')
            ->where('aplicado_em', '>=', $desde)
            ->selectRaw('animal_id, MAX(aplicado_em) as em')
            ->groupBy('animal_id')
            ->pluck('em', 'animal_id');

        $linhas = $autorizados
            ->map(function (Animal $animal) use ($porAtendimento, $porVacina, $carteiras) {
                $atendimento = $porAtendimento[$animal->id] ?? null;
                $vacina = $porVacina[$animal->id] ?? null;

                if ($atendimento === null && $vacina === null) {
                    return null;
                }

                // Empate entre uma vacina e um atendimento do mesmo instante é
                // decidido pelo atendimento: quem vacinou dentro de uma consulta
                // procura a consulta.
                $ehAtendimento = $atendimento !== null && ($vacina === null || $atendimento >= $vacina);

                return [
                    'codigo' => $animal->codigo,
                    'nome' => $animal->nome,
                    'especie' => $animal->especie,
                    'tutor' => $animal->tutor->nome,
                    'ultimo_registro' => [
                        'em' => ($ehAtendimento ? $atendimento : $vacina),
                        'tipo' => $ehAtendimento ? 'atendimento' : 'vacina',
                    ],
                    'situacao' => $this->calendario->situacaoDaCarteira($carteiras[$animal->id]),
                ];
            })
            ->filter()
            ->sortByDesc('ultimo_registro.em')
            ->values();

        $total = $linhas->count();
        $paginas = max(1, (int) ceil($total / self::ANIMAIS_POR_PAGINA));
        $pagina = min($pagina, $paginas);
        $deslocamento = ($pagina - 1) * self::ANIMAIS_POR_PAGINA;

        return [
            'itens' => $linhas->slice($deslocamento, self::ANIMAIS_POR_PAGINA)
                ->map(fn (array $linha) => [
                    ...$linha,
                    'ultimo_registro' => [
                        ...$linha['ultimo_registro'],
                        'em' => Carbon::parse($linha['ultimo_registro']['em'])->toDateString(),
                    ],
                ])
                ->values()
                ->all(),
            'paginacao' => [
                'pagina' => $pagina,
                'paginas' => $paginas,
                'total' => $total,
                'de' => $total === 0 ? 0 : $deslocamento + 1,
                'ate' => min($deslocamento + self::ANIMAIS_POR_PAGINA, $total),
            ],
        ];
    }
}
