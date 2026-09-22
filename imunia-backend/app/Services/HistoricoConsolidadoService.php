<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Prestador;
use App\Models\Vacinacao;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * T07 — histórico consolidado (RF35): tudo o que aconteceu com o animal em uma
 * ordem cronológica única, seja qual for o prestador de origem.
 *
 * Este serviço não calcula nada de novo. O rótulo da dose, a próxima prevista e
 * a procedência de cada aplicação vêm de `CalendarioVacinalService`, para que a
 * linha do tempo e a carteira (T05) nunca digam números diferentes sobre o
 * mesmo registro — é o mesmo motivo pelo qual T06 reaproveita o grupo em vez de
 * recalcular a dose (decisoes.md §5.2).
 */
class HistoricoConsolidadoService
{
    /**
     * Os tipos de entrada que RF35 exige na linha do tempo. Vacinação (RF25) e
     * atendimento com as suas retificações (RF31, RF33) já existem no esquema;
     * exame e óbito entram nas fatias que os criarem. O formato declarado aqui
     * é o mesmo para todas: tipo, data, título, resumo e procedência.
     */
    private const ROTULOS_DE_TIPO = [
        'vacinacao' => 'Vacinação',
        'atendimento' => 'Atendimento',
        'exame' => 'Exame',
        'retificacao' => 'Retificação',
        'obito' => 'Óbito',
    ];

    /**
     * Campos de identificação do imunobiológico e o gênero de cada um, porque
     * a frase de ausência é escrita em português e precisa concordar: "validade
     * não informada", "fabricante e lote não informados".
     */
    private const CAMPOS_DE_IDENTIFICACAO = [
        'fabricante' => ['rotulo' => 'fabricante', 'feminino' => false],
        'lote' => ['rotulo' => 'lote', 'feminino' => false],
        'validade' => ['rotulo' => 'validade', 'feminino' => true],
    ];

    public function __construct(
        private readonly CalendarioVacinalService $calendario,
        private readonly AtendimentoService $atendimentos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function montar(Animal $animal): array
    {
        $entradas = $this->entradasDeVacinacao($animal)
            // As demais fontes de RF35 entram aqui, cada uma na sua fatia, sem
            // que o resto deste método mude: o que importa é que toda entrada
            // fale a mesma língua — tipo, data, título, resumo e procedência.
            ->concat($this->atendimentos->entradasParaHistorico($animal))
            ->concat($this->entradaDeObito($animal))
            // O id só desempata dentro da mesma fonte; entre fontes diferentes,
            // o par tipo+id é que é único. Sem ele, uma vacinação e um
            // atendimento de mesma data e mesmo id ficariam em ordem arbitrária.
            ->sortByDesc(fn (array $entrada) => [$entrada['data'], $entrada['tipo'], $entrada['id']])
            ->values();

        return [
            'resumo' => $this->montarResumo($entradas),
            'filtros' => $this->montarFiltros($entradas),
            'entradas' => $entradas->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function entradasDeVacinacao(Animal $animal): Collection
    {
        $grupos = collect($this->calendario->montarCarteira($animal)['grupos']);

        // A carteira devolve o nome do prestador, que é o que T05 exibe, mas
        // não o id — e o filtro por prestador precisa de uma chave estável, que
        // nome nenhum garante. Esta é a única consulta a mais que T07 faz.
        //
        // Vêm todas as versões, e não só as vigentes: a carteira já cortou as
        // substituídas do cálculo (V09), e é justamente delas que sai o segundo
        // laço deste método.
        $registros = $animal->vacinacoes()
            ->with(['prestador', 'imunobiologico'])
            ->get()
            ->keyBy('id');

        $prestadores = $registros->map(fn (Vacinacao $vacinacao) => $vacinacao->prestador);

        return $grupos->flatMap(function (array $grupo) use ($prestadores, $registros) {
            $nome = $grupo['imunobiologico']['nome'];
            $prevista = collect($grupo['estacoes'])->firstWhere('tipo', '!=', 'aplicada');
            // `aplicacoes` chega da mais recente para a mais antiga: só a
            // primeira anuncia a próxima dose, senão a mesma previsão apareceria
            // repetida em cada dose da série.
            $maisRecente = $grupo['aplicacoes'][0]['id'] ?? null;

            return collect($grupo['aplicacoes'])->map(fn (array $aplicacao) => [
                'id' => $aplicacao['id'],

                // A aplicação que corrige outra se anuncia como correção, do
                // mesmo modo que a retificação de um atendimento (RF33): é ela
                // que vale, e o tutor precisa saber que houve uma versão antes.
                'tipo' => $registros->get($aplicacao['id'])?->ehRetificacao() ? 'retificacao' : 'vacinacao',
                'registro' => 'vacinacao',
                // O registro pregresso não recebe o rótulo da dose no título:
                // "Aplicação anterior" é justamente o que o chip de procedência
                // já diz, e repetir enfraqueceria a marca (RN24).
                'titulo' => Str::ucfirst($aplicacao['origem'] === 'pregresso'
                    ? $nome
                    : "{$nome} · {$aplicacao['rotulo']}"),
                'resumo' => $this->resumirVacinacao(
                    $aplicacao,
                    $aplicacao['id'] === $maisRecente ? $prevista : null
                ),
                'data' => $aplicacao['data'],
                'data_aproximada' => $aplicacao['data_aproximada'],
                'origem' => $aplicacao['origem'],
                'prestador' => $this->identificarPrestador($prestadores[$aplicacao['id']] ?? null),
                // Mesmas chaves de `aplicacoes[]`: o chip de procedência é
                // montado no cliente a partir delas, e assim o selo de lote e a
                // entrada da linha do tempo escrevem a mesma frase.
                'aplicador' => $aplicacao['aplicador'],
                'lancado_por' => $aplicacao['lancado_por'],
                // Anexo é do atendimento (RF32) e do exame, fatia futura. A
                // aplicação de vacina não tem nenhum, e a tela esconde o
                // contador enquanto for zero.
                'anexos' => 0,

                // RF33b — o vínculo com a versão que esta corrige, para que a
                // linha do tempo desenhe o conector entre as duas. Nulo em toda
                // aplicação que não seja retificação, que é a maioria.
                'vinculada_a' => $registros->get($aplicacao['id'])?->retifica_vacinacao_id,
            ]);
        })->concat($this->entradasDeVersaoSubstituida($registros));
    }

    /**
     * V12 — o óbito na linha do tempo (RF22, RF35). No máximo uma entrada, e
     * ela vem do próprio animal, não de uma tabela de registros: o óbito é
     * atributo dele. O id repete o do animal só para dar chave estável à lista
     * — dentro do tipo `obito` não há segundo com quem colidir.
     *
     * O resumo repete o compromisso de RF22b, que é o que quem lê a entrada —
     * o tutor em T07 inclusive — precisa saber: encerrou-se o calendário, não
     * o histórico. A causa entra quando informada; a ausência não vira "causa
     * não informada", porque aqui o silêncio não é pendência de ninguém.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function entradaDeObito(Animal $animal): Collection
    {
        if (! $animal->inativo()) {
            return collect();
        }

        $animal->loadMissing(['obitoRegistradoPor', 'obitoPrestador']);

        $frases = [];

        if ($animal->obito_causa !== null) {
            $frases[] = Str::ucfirst($animal->obito_causa).'.';
        }

        $frases[] = 'O calendário e os lembretes foram encerrados; o histórico permanece consultável.';

        return collect([[
            'id' => $animal->id,
            'tipo' => 'obito',
            'registro' => 'obito',
            'titulo' => 'Óbito',
            'resumo' => implode(' ', $frases),
            'data' => $animal->obito_em->toDateString(),

            // A data do óbito é declarada pelo profissional que o registrou
            // (RF22), sem a imprecisão que o pregresso carrega (RN25).
            'data_aproximada' => false,
            'origem' => 'profissional',
            'prestador' => $this->identificarPrestador($animal->obitoPrestador),
            'aplicador' => [
                'nome' => $animal->obitoRegistradoPor?->name,
                'crmv' => $animal->obito_registrado_crmv,
                'prestador' => $animal->obitoPrestador?->nome,
            ],
            'lancado_por' => null,
            'anexos' => 0,
            'vinculada_a' => null,
        ]]);
    }

    /**
     * V09 — as versões substituídas de aplicação (RF33), que a carteira não
     * traz porque já não contam como dose.
     *
     * Elas continuam no histórico pela mesma razão que o atendimento retificado
     * continua: RF33 manda o original permanecer "íntegro e visível, sinalizado
     * como retificado", e uma linha do tempo que só mostrasse a versão final
     * seria indistinguível de uma em que a correção apagou o que havia antes —
     * exatamente o que RN26 não admite.
     *
     * O resumo não repete lote nem validade da versão substituída: o que
     * importa a quem lê a linha do tempo não é o dado errado, é que houve
     * correção e onde ela está.
     *
     * @param  Collection<int, Vacinacao>  $registros
     * @return Collection<int, array<string, mixed>>
     */
    private function entradasDeVersaoSubstituida(Collection $registros): Collection
    {
        $substituidas = $registros->pluck('retifica_vacinacao_id')->filter();

        return $registros
            ->only($substituidas->all())
            ->values()
            ->map(fn (Vacinacao $vacinacao) => [
                'id' => $vacinacao->id,
                'tipo' => 'vacinacao',
                'registro' => 'vacinacao',
                'titulo' => Str::ucfirst(
                    $vacinacao->imunobiologico?->nome_comercial ?? 'Vacina não identificada'
                ).' · versão retificada',
                'resumo' => 'Este registro foi corrigido depois. As duas versões continuam visíveis, '
                    .'com o motivo da correção.',
                'data' => $vacinacao->aplicado_em?->toDateString(),
                'data_aproximada' => $vacinacao->data_aproximada,
                'origem' => $vacinacao->origem,
                'prestador' => $this->identificarPrestador($vacinacao->prestador),
                'aplicador' => $vacinacao->aplicador_nome === null ? null : [
                    'nome' => $vacinacao->aplicador_nome,
                    'crmv' => $vacinacao->aplicador_crmv,
                ],
                'lancado_por' => null,
                'anexos' => 0,
                'vinculada_a' => null,
            ]);
    }

    /**
     * O resumo de duas linhas da entrada. Diz o que se sabe do lote e, quando
     * for o registro mais recente do imunobiológico, o que vem a seguir.
     *
     * @param  array<string, mixed>  $aplicacao
     * @param  array<string, mixed>|null  $prevista  estação prevista do grupo
     */
    private function resumirVacinacao(array $aplicacao, ?array $prevista): string
    {
        $frases = [];

        $informados = collect(self::CAMPOS_DE_IDENTIFICACAO)
            ->filter(fn (array $campo, string $chave) => $aplicacao[$chave] !== null)
            ->map(fn (array $campo, string $chave) => $chave === 'validade'
                ? 'validade até '.Carbon::parse($aplicacao['validade'])->format('m/Y')
                : "{$campo['rotulo']} {$aplicacao[$chave]}");

        if ($informados->isNotEmpty()) {
            $frases[] = Str::ucfirst($informados->join(' · ')).'.';
        }

        // RN25 — o que não se sabe é dito, não omitido. É o mesmo princípio do
        // "não informado" do selo de lote, em forma de frase.
        $ausentes = collect(self::CAMPOS_DE_IDENTIFICACAO)
            ->reject(fn (array $campo, string $chave) => $aplicacao[$chave] !== null);

        if ($ausentes->isNotEmpty()) {
            $rotulos = $ausentes->pluck('rotulo');
            $concordancia = $ausentes->count() > 1
                ? 'não informados'
                : ($ausentes->first()['feminino'] ? 'não informada' : 'não informado');

            $frases[] = Str::ucfirst($rotulos->join(', ', ' e '))." {$concordancia}.";
        }

        if ($prevista !== null) {
            $data = Carbon::parse($prevista['data'])->format('d/m/Y');
            $frases[] = "Próxima dose: {$prevista['rotulo']} em {$data}.";
        }

        return implode(' ', $frases);
    }

    /**
     * @return array{chave: string, rotulo: string}
     */
    private function identificarPrestador(?Prestador $prestador): array
    {
        // O registro pregresso não tem prestador — e chamá-lo de prestador
        // seria emprestar-lhe responsabilidade técnica que ninguém assumiu.
        if ($prestador === null) {
            return ['chave' => 'sem-prestador', 'rotulo' => 'Lançado pelo tutor'];
        }

        return ['chave' => "prestador-{$prestador->id}", 'rotulo' => $prestador->nome];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entradas
     * @return array<string, mixed>
     */
    private function montarResumo(Collection $entradas): array
    {
        $maisAntiga = $entradas->last();

        return [
            'total' => $entradas->count(),
            // Conta prestadores, não origens: o pregresso é do tutor, e somá-lo
            // aqui inflaria a impressão de quantas clínicas cuidaram do animal.
            'prestadores' => $entradas
                ->pluck('prestador.chave')
                ->reject(fn (string $chave) => $chave === 'sem-prestador')
                ->unique()
                ->count(),
            'desde' => $maisAntiga['data'] ?? null,
            'desde_aproximada' => $maisAntiga['data_aproximada'] ?? false,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entradas
     * @return array<string, mixed>
     */
    private function montarFiltros(Collection $entradas): array
    {
        $porTipo = $entradas->countBy('tipo');

        return [
            // Só os tipos que o animal realmente tem, na ordem em que RF35 os
            // enumera: um filtro que não filtra nada é ruído na tela.
            'tipos' => collect(self::ROTULOS_DE_TIPO)
                ->filter(fn (string $rotulo, string $chave) => $porTipo->has($chave))
                ->map(fn (string $rotulo, string $chave) => [
                    'chave' => $chave,
                    'rotulo' => $rotulo,
                    'total' => $porTipo->get($chave),
                ])
                ->values()
                ->all(),
            'prestadores' => $entradas
                ->groupBy('prestador.chave')
                ->map(fn (Collection $grupo, string $chave) => [
                    'chave' => $chave,
                    'rotulo' => $grupo->first()['prestador']['rotulo'],
                    'total' => $grupo->count(),
                ])
                ->sortBy('rotulo')
                ->values()
                ->all(),
        ];
    }
}
