<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Imunobiologico;
use App\Models\Notificacao;
use App\Models\Prestador;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Painel de pendências vacinais do prestador (RF49, V02). É a tela que converte
 * a rechamada por amostragem — a recepcionista ligando de memória — em rechamada
 * por critério: quais animais sob nossos cuidados estão com dose vencida ou
 * prestes a vencer, e quem já foi avisado?
 *
 * Dois âmbitos, os mesmos de V01 e pelo mesmo motivo:
 *
 * - **prestador ativo** — a rechamada é de um estabelecimento determinado;
 * - **autorização vigente** (RN48) — animal sem autorização não figura no
 *   resultado, e essa é a exigência literal de RF49c.
 *
 * **Sobre o desempenho (RNF03).** `PainelVeterinarioService` registra que a
 * rechamada em escala "responde por consulta e não por laço". O laço que aquele
 * comentário condena é o N+1 — uma consulta de vacinações por animal —, e é ele
 * que desaparece aqui: as vacinações do plantel inteiro vêm carregadas junto dos
 * animais, e o cálculo roda em memória sobre o que já está na mão. O que não se
 * fez foi reescrever o calendário em SQL: a data prevista depende da versão do
 * protocolo aplicada a cada dose (RF24c, RN32), e uma segunda implementação da
 * mesma regra é a forma mais cara de errar duas vezes.
 */
class PendenciasVacinaisService
{
    /**
     * As janelas oferecidas pelo filtro de período. As mesmas de V01, para que
     * "30 dias" signifique a mesma coisa nas duas telas do ambiente clínico.
     *
     * @var list<int>
     */
    public const PERIODOS_EM_DIAS = [7, 30, 90];

    public const PERIODO_PADRAO_DIAS = 30;

    /** @var list<string> */
    public const SITUACOES = ['atrasada', 'proxima'];

    /** Linhas por página da tabela, no formato "Exibindo 1–25 de 137" (§5.1). */
    private const LINHAS_POR_PAGINA = 25;

    public function __construct(private readonly CalendarioVacinalService $calendario)
    {
    }

    /**
     * @param array{dias: int, especie: ?string, imunobiologico: ?string, situacao: ?string} $filtros
     * @return array<string, mixed>
     */
    public function consultar(Prestador $prestador, array $filtros, int $pagina): array
    {
        $animais = $this->animaisNoAmbito($prestador);

        // Sem filtro algum, para que a tela possa dizer "0 de 7 resultados" —
        // a diferença entre "não há pendência" e "os seus filtros escondem as
        // que há" é a diferença entre duas telas distintas (§8.3, V02).
        $todas = $this->pendencias($animais, $filtros['dias']);
        $filtradas = $this->aplicarFiltros($todas, $filtros);

        $total = $filtradas->count();
        $paginas = max(1, (int) ceil($total / self::LINHAS_POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $deslocamento = ($pagina - 1) * self::LINHAS_POR_PAGINA;

        return [
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'estado' => $animais->isEmpty() ? 'sem_autorizacoes' : 'normal',
            'filtros' => $this->descreverFiltros($filtros),

            // O denominador do vazio positivo: "os N animais sob autorização
            // vigente estão com o calendário em dia". Sem ele a confirmação
            // seria uma frase sobre coisa nenhuma.
            'animais_no_ambito' => $animais->count(),
            'total' => $total,
            'total_sem_filtros' => $todas->count(),
            'itens' => $filtradas->slice($deslocamento, self::LINHAS_POR_PAGINA)->values()->all(),
            'paginacao' => [
                'pagina' => $pagina,
                'paginas' => $paginas,
                'total' => $total,
                'de' => $total === 0 ? 0 : $deslocamento + 1,
                'ate' => min($deslocamento + self::LINHAS_POR_PAGINA, $total),
            ],
        ];
    }

    /**
     * RF49b — o resultado inteiro, sem paginar, para o arquivo que acompanha a
     * rechamada. Exportar a página em que se está seria exportar um recorte de
     * interface, e não a resposta à pergunta.
     *
     * @param array{dias: int, especie: ?string, imunobiologico: ?string, situacao: ?string} $filtros
     * @return Collection<int, array<string, mixed>>
     */
    public function paraExportacao(Prestador $prestador, array $filtros): Collection
    {
        $animais = $this->animaisNoAmbito($prestador);

        return $this->aplicarFiltros($this->pendencias($animais, $filtros['dias']), $filtros);
    }

    /**
     * RN48 — o âmbito, e a única consulta que o define. As vacinações vêm
     * junto: é o que permite calcular o plantel inteiro sem voltar ao banco por
     * animal.
     *
     * @return Collection<int, Animal>
     */
    private function animaisNoAmbito(Prestador $prestador): Collection
    {
        return Animal::query()
            ->sobAutorizacaoVigenteDe($prestador)
            ->with([
                'tutor',
                'vacinacoes' => fn ($consulta) => $consulta->orderBy('aplicado_em'),
                'vacinacoes.imunobiologico',
                'vacinacoes.protocoloVacinal',
                'vacinacoes.prestador',
                'vacinacoes.lancadoPor',
            ])
            ->get();
    }

    /**
     * Uma linha por dose prevista dentro da janela — não por animal. O cão com
     * antirrábica vencida e V10 a vencer aparece duas vezes porque são duas
     * doses a marcar, e a coluna "Dose" é o que as distingue.
     *
     * @param Collection<int, Animal> $animais
     * @return Collection<int, array<string, mixed>>
     */
    private function pendencias(Collection $animais, int $dias): Collection
    {
        $hoje = CarbonImmutable::today();
        $limite = $hoje->addDays($dias)->toDateString();
        $notificacoes = $this->ultimasNotificacoes($animais);
        $idPorChave = Imunobiologico::pluck('id', 'chave');

        return $animais
            ->flatMap(function (Animal $animal) use ($limite, $hoje, $notificacoes, $idPorChave) {
                $carteira = $this->calendario->montarCarteiraCom($animal, $animal->vacinacoes);

                return collect($carteira['proximas_doses'])
                    // "Em dia" não é pendência. O corte por data já exclui a
                    // maioria, mas a janela de 90 dias alcançaria doses que o
                    // calendário ainda considera tranquilas.
                    ->filter(fn (array $dose) => $dose['prevista_para'] <= $limite
                        && in_array($dose['situacao']['tipo'], self::SITUACOES, true))
                    ->map(function (array $dose) use ($animal, $hoje, $notificacoes, $idPorChave) {
                        $imunobiologicoId = $idPorChave[$dose['imunobiologico_chave']] ?? null;
                        $chaveDaNotificacao = $animal->id.'|'.($imunobiologicoId ?? '');

                        return [
                            'animal' => [
                                'codigo' => $animal->codigo,
                                'nome' => $animal->nome,
                                'especie' => $animal->especie,
                            ],
                            'tutor' => $animal->tutor->nome,
                            'imunobiologico' => $dose['imunobiologico'],
                            'imunobiologico_chave' => $dose['imunobiologico_chave'],
                            'dose' => $dose['rotulo_curto'],
                            'prevista_para' => $dose['prevista_para'],
                            'situacao' => $dose['situacao'],
                            'atraso' => $this->descreverAtraso($dose['prevista_para'], $hoje),

                            // RF49 — "indicação da última notificação enviada".
                            // Nula quando nunca se avisou, e a tela diz isso com
                            // todas as letras: o silêncio do sistema não pode
                            // parecer silêncio do tutor.
                            'ultima_notificacao' => $notificacoes->get($chaveDaNotificacao)?->paraColuna(),
                        ];
                    });
            })
            // Ordenar pela data prevista, da mais antiga para a mais recente, é
            // ordenar por dias de atraso decrescente — que é o que o desenho
            // pede — sem precisar ordenar por situação antes.
            ->sortBy('prevista_para')
            ->values();
    }

    /**
     * A última notificação de cada série, indexada por animal e imunobiológico.
     * Uma consulta para o plantel inteiro: a ordenação crescente faz `keyBy`
     * conservar a mais recente de cada chave, porque a última a ser indexada
     * vence as anteriores.
     *
     * @param Collection<int, Animal> $animais
     * @return Collection<string, Notificacao>
     */
    private function ultimasNotificacoes(Collection $animais): Collection
    {
        return Notificacao::whereIn('animal_id', $animais->modelKeys())
            ->orderBy('enviada_em')
            ->get()
            ->keyBy(fn (Notificacao $n) => $n->animal_id.'|'.($n->imunobiologico_id ?? ''));
    }

    /**
     * "42 dias" para o que venceu, "em 3 dias" para o que vai vencer. É a
     * coluna que ordena a tabela, e por isso carrega o número separado do texto:
     * a tela ordena por gente, o servidor por dias.
     *
     * @return array{dias: int, vencida: bool, texto: string}
     */
    private function descreverAtraso(string $previstaPara, CarbonImmutable $hoje): array
    {
        $prevista = CarbonImmutable::parse($previstaPara);

        if ($prevista->lt($hoje)) {
            $dias = (int) $prevista->diffInDays($hoje);

            return [
                'dias' => $dias,
                'vencida' => true,
                'texto' => $dias === 1 ? '1 dia' : "{$dias} dias",
            ];
        }

        $dias = (int) $hoje->diffInDays($prevista);

        return [
            'dias' => -$dias,
            'vencida' => false,
            'texto' => match (true) {
                $dias === 0 => 'hoje',
                $dias === 1 => 'amanhã',
                default => "em {$dias} dias",
            },
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $pendencias
     * @param array{dias: int, especie: ?string, imunobiologico: ?string, situacao: ?string} $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function aplicarFiltros(Collection $pendencias, array $filtros): Collection
    {
        return $pendencias
            ->when(
                $filtros['especie'] !== null,
                fn (Collection $linhas) => $linhas->where('animal.especie', $filtros['especie']),
            )
            ->when(
                $filtros['imunobiologico'] !== null,
                fn (Collection $linhas) => $linhas->where('imunobiologico_chave', $filtros['imunobiologico']),
            )
            ->when(
                $filtros['situacao'] !== null,
                fn (Collection $linhas) => $linhas->where('situacao.tipo', $filtros['situacao']),
            )
            ->values();
    }

    /**
     * Os filtros como a tela precisa deles: o que está aplicado e o que se pode
     * escolher. As opções de imunobiológico saem do catálogo inteiro (RF23), e
     * não das pendências existentes — o desenho prevê a combinação que não
     * retorna nada ("gato" e "leptospirose"), e ela precisa ser escolhível para
     * que a tela possa explicar por que não retornou.
     *
     * @param array{dias: int, especie: ?string, imunobiologico: ?string, situacao: ?string} $filtros
     * @return array<string, mixed>
     */
    private function descreverFiltros(array $filtros): array
    {
        return [
            ...$filtros,
            'opcoes' => [
                'periodos' => self::PERIODOS_EM_DIAS,
                'especies' => [
                    ['valor' => 'cao', 'rotulo' => 'Cão'],
                    ['valor' => 'gato', 'rotulo' => 'Gato'],
                ],
                'imunobiologicos' => Imunobiologico::where('ativo', true)
                    ->orderBy('nome_comercial')
                    ->get()
                    ->map(fn (Imunobiologico $i) => ['valor' => $i->chave, 'rotulo' => $i->nome_comercial])
                    ->all(),
                'situacoes' => [
                    ['valor' => 'atrasada', 'rotulo' => 'Atrasada'],
                    ['valor' => 'proxima', 'rotulo' => 'Próxima do vencimento'],
                ],
            ],
        ];
    }
}
