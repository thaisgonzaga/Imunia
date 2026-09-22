<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\Vacinacao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Relação de animais da clínica — o destino "Animais" da barra lateral (§5.3
 * do briefing). É a lista de navegação do plantel: quem está sob os cuidados
 * deste prestador, em que situação vacinal, quando passou por aqui pela última
 * vez e até quando a autorização vale.
 *
 * Não é busca. Achar um animal determinado é papel de V03, que responde também
 * sobre o que está fora do âmbito — com a parcimônia de RN12 e o log de RF18b.
 * Esta lista responde só sobre o que está dentro, e por isso o âmbito é o
 * mesmo de V01 e V02, pelos mesmos motivos: prestador ativo (RF48a) e
 * autorização vigente (RN48).
 *
 * A ordem é alfabética, e não por urgência: lista de navegação se percorre
 * como catálogo, e a urgência já tem tela própria — V02, a um clique na mesma
 * barra lateral.
 */
class AnimaisDaClinicaService
{
    /**
     * Os valores que o filtro de situação aceita. Quatro são os tipos que o
     * calendário atribui a uma carteira; o quinto é a ausência dela — a
     * carteira sem vacinação registrada, que RF50 manda apresentar como "o
     * sistema ainda não sabe", nunca como "em dia" por omissão.
     *
     * @var list<string>
     */
    public const SITUACOES = ['atrasada', 'proxima', 'nao-verificada', 'em-dia', 'sem-registro'];

    /** Linhas por página da tabela, no formato "Exibindo 1–25 de 137" (§5.1). */
    private const LINHAS_POR_PAGINA = 25;

    public function __construct(private readonly CalendarioVacinalService $calendario) {}

    /**
     * @param  array{especie: ?string, situacao: ?string}  $filtros
     * @return array<string, mixed>
     */
    public function consultar(Prestador $prestador, array $filtros, int $pagina): array
    {
        $animais = $this->animaisNoAmbito($prestador);

        // Sem filtro algum, para que a tela possa dizer "3 de 41 animais" — a
        // diferença entre "o plantel é este" e "os seus filtros escondem o
        // resto" precisa ser dita, como em V02.
        $todas = $this->linhas($animais, $prestador);
        $filtradas = $this->aplicarFiltros($todas, $filtros);

        $total = $filtradas->count();
        $paginas = max(1, (int) ceil($total / self::LINHAS_POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $deslocamento = ($pagina - 1) * self::LINHAS_POR_PAGINA;

        return [
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'estado' => $animais->isEmpty() ? 'sem_autorizacoes' : 'normal',
            'filtros' => $this->descreverFiltros($filtros),
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
     * RN48 — o âmbito, e a única consulta que o define. As vacinações vêm
     * junto, como em V02: é o que permite montar a situação do plantel inteiro
     * sem voltar ao banco por animal. A autorização vigente vem também, porque
     * a coluna de vencimento sai dela.
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
                'autorizacoes' => fn ($consulta) => $consulta
                    ->where('prestador_id', $prestador->id)
                    ->vigente(),
            ])
            ->orderBy('nome')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Animal>  $animais
     * @return Collection<int, array<string, mixed>>
     */
    private function linhas(Collection $animais, Prestador $prestador): Collection
    {
        $passagens = $this->ultimasPassagens($animais, $prestador);

        return $animais->map(fn (Animal $animal) => $this->linha($animal, $passagens->get($animal->id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function linha(Animal $animal, ?string $ultimaPassagem): array
    {
        // RF22a — registrado o óbito, cessa o cálculo do calendário. Uma
        // etiqueta de "atrasada" sobre quem morreu não é informação clínica:
        // a linha carrega a marca de óbito, e nenhuma situação vacinal.
        $situacao = $animal->inativo()
            ? null
            : $this->calendario->situacaoDaCarteira(
                $this->calendario->montarCarteiraCom($animal, $animal->vacinacoes),
            );

        // A vigente de vencimento mais distante, como na ficha (V06): pode
        // haver mais de uma quando o tutor renovou antes do prazo.
        $autorizacao = $animal->autorizacoes->sortByDesc('expira_em')->first();

        return [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,
            'idade_em_meses' => $animal->idadeEmMeses(),
            'nascimento_exato' => $animal->nascimento_exato, // RN14
            'preliminar' => $animal->preliminar(), // RF16d, RN17
            'obito' => $animal->inativo(),
            'tutor' => $animal->tutor->nome,
            'situacao' => $situacao,
            'ultima_passagem' => $ultimaPassagem === null
                ? null
                : CarbonImmutable::parse($ultimaPassagem)->toDateString(),

            // O vencimento é informação de trabalho da clínica: renovar é do
            // tutor (RF40c), mas saber até quando se enxerga o histórico é
            // daqui — o mesmo aviso de quinze dias da ficha e de T12 (RN39).
            'autorizacao' => [
                'expira_em' => $autorizacao->expira_em->toDateString(),
                'dias_restantes' => $autorizacao->diasRestantes(),
                'a_expirar' => $autorizacao->situacao() === 'a_expirar',
            ],
        ];
    }

    /**
     * A data do último registro de cada animal neste prestador, vinda das duas
     * origens que contam como passagem pelo balcão — o atendimento (RF31) e a
     * aplicação de vacina (RF25) —, sem janela: a pergunta é "quando este
     * animal esteve aqui pela última vez?", e a resposta pode ser antiga. Duas
     * consultas agregadas, e não uma por animal, como em V07a.
     *
     * @param  Collection<int, Animal>  $animais
     * @return Collection<int, string>
     */
    private function ultimasPassagens(Collection $animais, Prestador $prestador): Collection
    {
        if ($animais->isEmpty()) {
            return collect();
        }

        $porAtendimento = Atendimento::where('prestador_id', $prestador->id)
            ->whereIn('animal_id', $animais->modelKeys())
            ->selectRaw('animal_id, MAX(atendido_em) as em')
            ->groupBy('animal_id')
            ->pluck('em', 'animal_id');

        // Vacinação de origem `pregresso` é o que o tutor lançou de memória
        // (RF29): não houve passagem por este prestador.
        $porVacina = Vacinacao::where('prestador_id', $prestador->id)
            ->where('origem', 'profissional')
            ->whereIn('animal_id', $animais->modelKeys())
            ->selectRaw('animal_id, MAX(aplicado_em) as em')
            ->groupBy('animal_id')
            ->pluck('em', 'animal_id');

        return $porAtendimento->keys()
            ->merge($porVacina->keys())
            ->unique()
            ->mapWithKeys(fn (int $id) => [
                $id => max($porAtendimento[$id] ?? '', $porVacina[$id] ?? ''),
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @param  array{especie: ?string, situacao: ?string}  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function aplicarFiltros(Collection $linhas, array $filtros): Collection
    {
        return $linhas
            ->when(
                $filtros['especie'] !== null,
                fn (Collection $itens) => $itens->where('especie', $filtros['especie']),
            )
            ->when(
                $filtros['situacao'] !== null,
                fn (Collection $itens) => $itens->filter(
                    fn (array $linha) => $this->casaComSituacao($linha, $filtros['situacao']),
                ),
            )
            ->values();
    }

    private function casaComSituacao(array $linha, string $situacao): bool
    {
        // "Sem registro" é a carteira vazia de quem está vivo. O animal com
        // óbito também não tem situação, mas por outro motivo — e oferecê-lo a
        // quem filtrou por vacinação pendente de registro seria mentir duas
        // vezes na mesma linha.
        if ($situacao === 'sem-registro') {
            return $linha['situacao'] === null && ! $linha['obito'];
        }

        return ($linha['situacao']['tipo'] ?? null) === $situacao;
    }

    /**
     * @param  array{especie: ?string, situacao: ?string}  $filtros
     * @return array<string, mixed>
     */
    private function descreverFiltros(array $filtros): array
    {
        return [
            ...$filtros,
            'opcoes' => [
                'especies' => [
                    ['valor' => 'cao', 'rotulo' => 'Cão'],
                    ['valor' => 'gato', 'rotulo' => 'Gato'],
                ],
                'situacoes' => [
                    ['valor' => 'atrasada', 'rotulo' => 'Vacinação atrasada'],
                    ['valor' => 'proxima', 'rotulo' => 'Próxima do vencimento'],
                    ['valor' => 'em-dia', 'rotulo' => 'Em dia'],
                    ['valor' => 'nao-verificada', 'rotulo' => 'Não verificada'],
                    ['valor' => 'sem-registro', 'rotulo' => 'Sem registro de vacinação'],
                ],
            ],
        ];
    }
}
