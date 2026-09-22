<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\ProtocoloVacinal;
use App\Models\Vacinacao;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Cálculo do calendário vacinal (RF26, RF27) e montagem da carteira digital
 * (RF28, T05). Regra de negócio vive aqui, nunca em controlador
 * (decisoes.md §5.2): o controller só chama `montarCarteira()` e devolve o
 * resultado.
 *
 * O sistema apoia a decisão clínica, não a substitui (RF27) — este serviço
 * só produz previsão e justificativa; não há aqui caminho algum que impeça o
 * registro de conduta divergente, porque este serviço não registra nada.
 */
class CalendarioVacinalService
{
    /**
     * Janela, em dias, dentro da qual uma dose prevista deixa de ser "em dia"
     * e passa a ser "próxima" — mesmo vocabulário usado no painel (T01).
     */
    private const JANELA_PROXIMA_DIAS = 30;

    /**
     * Chave do grupo das aplicações que não identificam a vacina (RF29). Não é
     * id de imunobiológico algum, e não pode ser: o grupo existe justamente
     * porque não há imunobiológico a que se referir.
     */
    private const GRUPO_SEM_IMUNOBIOLOGICO = 'sem-imunobiologico';

    /**
     * @return array<string, mixed>
     */
    public function montarCarteira(Animal $animal): array
    {
        return $this->montarCarteiraCom($animal, $animal->vacinacoes()
            ->with(['imunobiologico', 'protocoloVacinal', 'prestador', 'lancadoPor'])
            ->orderBy('aplicado_em')
            ->get());
    }

    /**
     * A mesma carteira, para quem já trouxe as vacinações do banco. Existe por
     * causa de V02 (RF49): a rechamada percorre o plantel inteiro do prestador,
     * e `montarCarteira()` faria uma consulta por animal — o laço que RNF03 não
     * comporta. Com as vacinações carregadas de uma vez, a tela inteira sai em
     * duas consultas, e o cálculo continua sendo este, e não um segundo escrito
     * em SQL: duas fontes de verdade para a data prevista seria pior do que
     * lento.
     *
     * @param  Collection<int, Vacinacao>  $vacinacoes  ordenadas por `aplicado_em`
     * @return array<string, mixed>
     */
    public function montarCarteiraCom(Animal $animal, Collection $vacinacoes): array
    {
        $vacinacoes = $this->somenteVersoesVigentes($vacinacoes);

        // A chave do agrupamento é explícita porque o histórico pregresso pode
        // não identificar a vacina (RF29, T09): as aplicações sem
        // imunobiológico formam um grupo só, e nunca se misturam a um
        // imunobiológico real por coincidência de chave vazia.
        $grupos = $vacinacoes
            ->groupBy(fn (Vacinacao $dose) => $dose->imunobiologico_id ?? self::GRUPO_SEM_IMUNOBIOLOGICO)
            ->map(fn (Collection $doses) => $this->montarGrupo($animal, $doses))
            ->values()
            ->sortBy(fn (array $grupo) => $this->pesoDeOrdenacao($grupo['situacao']))
            ->values()
            ->all();

        return [
            'resumo' => $this->montarResumo($grupos, $vacinacoes),
            'proximas_doses' => $this->montarProximasDoses($grupos),
            'grupos' => $grupos,
        ];
    }

    /**
     * A última versão da cadeia de retificações a partir de uma aplicação
     * qualquer — ela mesma, quando ninguém a corrigiu (RF33).
     *
     * A cadeia existe porque a retificação também é registro clínico imutável
     * (RN26): corrigir uma correção é criar uma terceira versão, nunca reabrir
     * a segunda.
     */
    public function versaoVigenteDe(Vacinacao $vacinacao): Vacinacao
    {
        $atual = $vacinacao;

        while ($atual->retificacao !== null) {
            $atual = $atual->retificacao;
        }

        return $atual;
    }

    /**
     * V09 — a versão substituída de uma aplicação sai do cálculo (RF33, RN26).
     *
     * A aplicação e a sua retificação descrevem **uma** dose: o que mudou foi o
     * lote, o sítio, a ordem declarada. Contar as duas deslocaria o rótulo de
     * todas as doses seguintes e a data da próxima (RF26) — o tutor veria a
     * carteira ganhar uma dose porque alguém corrigiu o número de um lote.
     *
     * O corte é feito aqui, e não em cada consulta, porque este é o funil por
     * onde passa todo cálculo do sistema: carteira do tutor (T05), rechamada do
     * veterinário (V02), busca (V03) e a prévia ao vivo de V07. Uma consulta
     * esquecida seria uma tela contando duas doses onde houve uma.
     *
     * O corte é feito pela própria coleção, sem consulta: quem chega aqui traz
     * as aplicações do animal inteiro, de modo que a retificação e o registro
     * que ela corrige estão ambos em mãos — inclusive quando a correção foi
     * justamente do imunobiológico, e as duas versões caem em grupos
     * diferentes. A dose hipotética de V07, que ainda não tem id, nunca
     * substitui coisa alguma.
     *
     * @param  Collection<int, Vacinacao>  $vacinacoes
     * @return Collection<int, Vacinacao>
     */
    private function somenteVersoesVigentes(Collection $vacinacoes): Collection
    {
        $substituidas = $vacinacoes->pluck('retifica_vacinacao_id')->filter()->all();

        if ($substituidas === []) {
            return $vacinacoes;
        }

        return $vacinacoes
            ->reject(fn (Vacinacao $dose) => $dose->id !== null && in_array($dose->id, $substituidas))
            ->values();
    }

    /**
     * Situação única do animal — a mais urgente entre seus grupos —, usada
     * pelo cartão do animal (T01, T02) e pelo resumo do perfil (T04). Nula
     * quando não há vacinação alguma registrada: RF50 exige dizer "ainda não
     * sei", nunca "em dia" por omissão.
     *
     * @return array{tipo: string, texto: string, texto_curto: string}|null
     */
    public function situacaoGeral(Animal $animal): ?array
    {
        return $this->situacaoDaCarteira($this->montarCarteira($animal));
    }

    /**
     * A mesma situação, para quem já montou a carteira. O painel do veterinário
     * (V01) monta uma por animal autorizado e usa o resultado duas vezes — nas
     * pendências e na coluna de situação da tabela —; sem esta porta de entrada,
     * a segunda pergunta refaria a consulta inteira.
     *
     * @param  array<string, mixed>  $carteira
     * @return array{tipo: string, texto: string, texto_curto: string}|null
     */
    public function situacaoDaCarteira(array $carteira): ?array
    {
        if ($carteira['grupos'] === []) {
            return null;
        }

        $piorGrupo = collect($carteira['grupos'])
            ->sortBy(fn (array $grupo) => $this->pesoDeOrdenacao($grupo['situacao']))
            ->first();

        return $piorGrupo['situacao'];
    }

    /**
     * T06 — detalhe de uma aplicação (RF25, RF30). Reaproveita `montarGrupo()`
     * para que o rótulo da dose, a próxima dose prevista e a versão do
     * protocolo sejam exatamente os mesmos números que a carteira (T05) já
     * mostrou — nenhuma segunda fonte de cálculo.
     *
     * @return array<string, mixed>
     */
    public function detalheAplicacao(Animal $animal, Vacinacao $vacinacao): array
    {
        // V09 — o grupo é o da versão que vale hoje, e não o da versão pedida.
        // As duas coincidem em todo registro que ninguém corrigiu; quando a
        // aplicação foi retificada, quem ocupa o lugar dela na série é a
        // correção, e é o imunobiológico **dela** que define o grupo — a
        // retificação pode ter sido justamente do imunobiológico trocado.
        $vigente = $this->versaoVigenteDe($vacinacao);

        $doses = $animal->vacinacoes()
            ->vigente()
            ->with(['imunobiologico', 'protocoloVacinal', 'prestador', 'lancadoPor'])
            // O mesmo grupo que a carteira montou — inclusive quando o grupo é
            // o das aplicações sem vacina identificada (RF29), em que a
            // igualdade com nulo não seleciona registro algum.
            ->when(
                $vigente->imunobiologico_id === null,
                fn ($consulta) => $consulta->whereNull('imunobiologico_id'),
                fn ($consulta) => $consulta->where('imunobiologico_id', $vigente->imunobiologico_id),
            )
            ->orderBy('aplicado_em')
            ->get();

        $grupo = $this->montarGrupo($animal, $doses);

        // A versão substituída não está entre as aplicações do grupo, e não
        // deve estar: ela não é uma segunda dose. Continua tendo endereço
        // próprio, porque é por ele que o encadeamento de RF33b leva quem quer
        // ver o que estava escrito antes — e o rótulo que lhe cabe é o da
        // correção que tomou o seu lugar na série, que é a mesma dose.
        $aplicacao = collect($grupo['aplicacoes'])->firstWhere('id', $vacinacao->id)
            ?? $this->montarAplicacao(
                $vacinacao,
                collect($grupo['aplicacoes'])->firstWhere('id', $vigente->id)['rotulo'] ?? 'Versão retificada',
            );

        return [
            'imunobiologico' => $grupo['imunobiologico'],
            'aplicacao' => [
                ...$aplicacao,
                // RF25 — só a origem profissional carimba hora exata; o
                // pregresso guarda no máximo uma data aproximada (RN25).
                'hora' => $vacinacao->origem === 'profissional' ? $vacinacao->aplicado_em?->format('H:i') : null,
                'protocolo_versao' => $vacinacao->protocoloVacinal?->versao,
            ],
            'proxima_dose' => $grupo['proxima_dose'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function montarGrupo(Animal $animal, Collection $doses): array
    {
        /** @var Vacinacao $primeira */
        $primeira = $doses->first();
        $imunobiologico = $primeira->imunobiologico;

        $protocolo = $doses
            ->sortByDesc('aplicado_em')
            ->pluck('protocoloVacinal')
            ->filter()
            ->first() ?? $imunobiologico?->protocoloVigente();

        $rotulos = $this->rotularDoses($doses, $protocolo);

        // Só uma data exata ancora a série (RN25). A ausência de data é a forma
        // extrema da mesma imprecisão, e desqualifica a dose pelo mesmo motivo.
        $ultimaExata = $doses
            ->filter(fn (Vacinacao $v) => ! $v->data_aproximada && $v->aplicado_em !== null)
            ->sortByDesc('aplicado_em')
            ->first();

        $proximaDose = null;
        $estacaoPrevista = null;
        $situacaoConcluida = null;

        // RF22a — registrado o óbito, cessa o cálculo do calendário. O corte é
        // aqui, no funil por onde passa todo cálculo do sistema, e não em cada
        // consumidor: uma tela esquecida seria uma dose prevista para um animal
        // que morreu — na ficha, na rechamada de V02 ou no lembrete ao tutor,
        // que é exatamente o que o requisito manda não emitir. As aplicações e
        // estações passadas ficam: histórico não é previsão.
        if ($protocolo !== null && $ultimaExata !== null && ! $animal->inativo()) {
            [$proximaDose, $estacaoPrevista, $situacaoConcluida] = $this->calcularProximaDose($animal, $doses, $protocolo, $ultimaExata);
        }

        // O trilho é uma linha do tempo: a dose sem data não tem lugar nele, e
        // inventar-lhe um seria afirmar quando aconteceu. Ela continua visível
        // como selo de lote, que é onde a ausência pode ser dita.
        $estacoes = $doses->values()
            ->filter(fn (Vacinacao $dose) => $dose->aplicado_em !== null)
            ->map(fn (Vacinacao $dose) => [
                'tipo' => 'aplicada',
                'origem' => $dose->origem,
                'rotulo' => $rotulos[$dose->id],
                'data' => $dose->aplicado_em->toDateString(),
                'data_aproximada' => $dose->data_aproximada,
            ])
            ->values()
            ->all();

        if ($estacaoPrevista !== null) {
            $estacoes[] = $estacaoPrevista;
        }

        // O texto curto é o mesmo conteúdo sem o prefixo que o ícone já dá:
        // a tabela do veterinário (V01) é densa, tem 14 px de corpo e repete a
        // etiqueta em cada linha, onde "atrasada · há 42 dias" diz duas vezes o
        // que o triângulo vermelho já disse uma.
        //
        // Para o animal com óbito, "sem data suficiente para calcular" seria
        // mentira educada — data havia; o cálculo é que cessou (RF22a). A
        // etiqueta diz isso, em tom neutro (§4.1): nem erro, nem alerta.
        $situacao = $proximaDose['situacao'] ?? $situacaoConcluida ?? ($animal->inativo() ? [
            'tipo' => 'encerrada',
            'texto' => 'calendário encerrado',
            'texto_curto' => 'encerrado',
        ] : [
            'tipo' => 'nao-verificada',
            'texto' => 'sem data suficiente para calcular',
            'texto_curto' => 'sem data',
        ]);

        return [
            // RF29 — o grupo sem imunobiológico se anuncia pelo que é: uma
            // aplicação que aconteceu e cuja vacina ninguém soube nomear. Não
            // tem classificação, porque classificar exigiria saber qual é.
            'imunobiologico' => [
                'chave' => $imunobiologico?->chave ?? self::GRUPO_SEM_IMUNOBIOLOGICO,
                'nome' => $imunobiologico?->nome_comercial ?? 'Vacina não identificada',
                'classificacao' => $imunobiologico?->classificacao,
            ],
            'situacao' => $situacao,
            'proxima_dose' => $proximaDose,
            'estacoes' => $estacoes,
            'aplicacoes' => $doses->sortByDesc('aplicado_em')->values()->map(
                fn (Vacinacao $dose) => $this->montarAplicacao($dose, $rotulos[$dose->id])
            )->all(),
        ];
    }

    /**
     * Rótulo de cada dose já aplicada, em ordem cronológica: ordinal enquanto
     * a série primária não se completa, "Reforço" a partir daí. O pregresso
     * conta para completar a série (aconteceu, mesmo sem precisão de data),
     * mas nunca recebe rótulo ordinal — permanece "Aplicação anterior", que é
     * o que a distingue visualmente (RF29c).
     *
     * @return array<int, string> rótulo indexado pelo id da vacinação
     */
    private function rotularDoses(Collection $doses, ?ProtocoloVacinal $protocolo): array
    {
        $rotulos = [];

        $doses->values()->each(function (Vacinacao $dose, int $indice) use (&$rotulos, $protocolo) {
            // A ordem declarada pelo profissional tem precedência sobre a
            // posição (RF27b, RN36). Quando o veterinário reinicia a série, ele
            // registra "1ª dose" sobre um animal que já tem quatro aplicações;
            // rotular pela posição diria "5ª dose" e desmentiria, na carteira do
            // tutor, a conduta que o próprio sistema aceitou registrar. O
            // pregresso não declara ordem alguma (RN25) e continua pela posição.
            $ordem = $dose->ordem_dose ?? $indice + 1;

            $rotulos[$dose->id] = $dose->pregresso()
                ? 'Aplicação anterior'
                : $this->rotuloDaDose($ordem, $protocolo);
        });

        return $rotulos;
    }

    /**
     * O rótulo de uma ordem de dose qualquer, dentro de um protocolo — a mesma
     * regra que `rotularDoses()` aplica ao que já foi aplicado, aberta para
     * quem precisa nomear uma dose que ainda não existe.
     *
     * É de V07 a necessidade: o seletor de ordem da dose oferece "1ª dose",
     * "3ª dose · final da série" e "Reforço anual" como opções, e essas
     * palavras têm de ser as mesmas que a carteira do tutor usará depois de
     * gravado o registro. Duas tabelas de rótulos seriam duas maneiras de a
     * mesma dose se chamar em duas telas.
     */
    public function rotuloDaDose(int $ordem, ?ProtocoloVacinal $protocolo): string
    {
        $numeroDoses = $protocolo?->numero_doses_serie_primaria ?? 1;

        if ($ordem > $numeroDoses) {
            // A primeira dose depois da série é a que `preverDoseSeguinte()`
            // trata pelo prazo do reforço inicial. O seletor de V07 precisa
            // chamá-la pelo mesmo nome que a carteira usará depois de gravada.
            return $this->rotuloDeReforco($protocolo, $ordem === $numeroDoses + 1);
        }

        return "{$ordem}ª dose".($ordem === $numeroDoses && $numeroDoses > 1 ? ' · final da série' : '');
    }

    private function rotuloDeReforco(?ProtocoloVacinal $protocolo, bool $primeiro = false): string
    {
        // Sem protocolo não há periodicidade a nomear; sem revacinação também
        // não — e neste caso o rótulo genérico nunca chega à tela, porque
        // `opcoesDeOrdem()` para na última dose da série. O guard existe porque
        // o método é público por `rotuloDaDose()` e não pode explodir com nulo.
        if ($protocolo === null || $protocolo->semRevacinacao()) {
            return 'Reforço';
        }

        if ($primeiro && $protocolo->reforco_inicial_meses !== null) {
            return 'Primeiro reforço';
        }

        return $this->humanizarPeriodicidade($protocolo->periodicidade_revacinacao_meses);
    }

    /**
     * A previsão de uma dose a partir da anterior, isolada do que a carteira
     * faz com ela — data, rótulo, justificativa e qual regra do protocolo
     * respondeu.
     *
     * Está pública por causa do simulador de X02: RNF01 exige que o cálculo
     * seja reprodutível, e uma tela que existe para conferir o cálculo não pode
     * calcular por conta própria — conferiria a si mesma. É este método que a
     * carteira do tutor, a rechamada do veterinário e o simulador chamam.
     *
     * @param  int  $dosesAplicadas  quantas doses da série já foram dadas
     * @return array{data: ?Carbon, rotulo: string, regra_texto: string, tipo: string}
     *                                                                                `data` é nula quando o protocolo não prevê revacinação:
     *                                                                                a série acabou, e não há dose seguinte a prever
     */
    public function preverDoseSeguinte(
        ProtocoloVacinal $protocolo,
        Carbon $ultimaAplicacao,
        int $dosesAplicadas,
        ?Carbon $nascimentoEm = null,
    ): array {
        $numeroDoses = $protocolo->numero_doses_serie_primaria;

        if ($dosesAplicadas >= $numeroDoses) {
            // Dose única sem revacinação — o agendamento que uma clínica declara
            // em A04 para um imunobiológico próprio que não se repete. A data
            // nula aqui não é falta de dado: o cálculo correu, e a resposta dele
            // é que não haverá outra dose. Quem consome precisa distinguir os
            // dois casos, porque "sem data suficiente para calcular" na carteira
            // do tutor lê-se como falha do sistema (RF50).
            if ($protocolo->semRevacinacao()) {
                return [
                    'data' => null,
                    'rotulo' => 'Série concluída',
                    'regra_texto' => 'Dose única — este agendamento não prevê revacinação.',
                    'tipo' => 'serie_concluida',
                ];
            }

            $primeiroReforco = $dosesAplicadas === $numeroDoses;
            $periodicidade = $this->humanizarPeriodicidade($protocolo->periodicidade_revacinacao_meses);

            // Quando a diretriz distingue o prazo do primeiro reforço da
            // periodicidade que vem depois dele — o padrão da WSAVA para as
            // essenciais felinas, reforço aos 6 meses e revacinação trienal — a
            // data andava por um prazo e o texto explicava o outro: a carteira
            // dizia "Reforço a cada 3 anos" ao lado de uma data de 6 meses. São
            // duas regras, e o rótulo passa a nomear a que de fato respondeu.
            if ($primeiroReforco && $protocolo->reforco_inicial_meses !== null) {
                $meses = $protocolo->reforco_inicial_meses;

                return [
                    'data' => $ultimaAplicacao->copy()->addMonths($meses),
                    'rotulo' => 'Primeiro reforço',
                    'regra_texto' => "Primeiro reforço {$this->prazoEmTexto($meses)} após a série primária, "
                        ."contado a partir da última aplicação ({$ultimaAplicacao->format('d/m/Y')}). "
                        .'Depois, '.lcfirst($periodicidade).'.',
                    'tipo' => 'reforco_inicial',
                ];
            }

            $meses = $primeiroReforco
                ? $protocolo->primeiroReforcoMeses()
                : $protocolo->periodicidade_revacinacao_meses;

            return [
                'data' => $ultimaAplicacao->copy()->addMonths($meses),
                'rotulo' => $periodicidade,
                'regra_texto' => "{$periodicidade}, contado a partir da última aplicação ({$ultimaAplicacao->format('d/m/Y')}).",
                'tipo' => $primeiroReforco ? 'reforco_inicial' : 'revacinacao',
            ];
        }

        $ordemProxima = $dosesAplicadas + 1;
        $ehDoseFinal = $ordemProxima === $numeroDoses;
        $intervalo = $protocolo->intervaloPrevistoDias();

        $data = $ultimaAplicacao->copy()->addDays($intervalo);
        $rotulo = "{$ordemProxima}ª dose".($ehDoseFinal && $numeroDoses > 1 ? ' · final da série' : '');

        // RN33 — a dose final da série primária de filhotes não pode ser
        // aplicada antes da idade mínima; se o intervalo normal cairia antes
        // dela, a dose empurra para a idade mínima, e o tipo muda para deixar
        // a razão visível.
        if ($ehDoseFinal && $protocolo->idade_minima_dose_final_semanas !== null && $nascimentoEm !== null) {
            $dataMinimaPorIdade = $nascimentoEm->copy()->addWeeks($protocolo->idade_minima_dose_final_semanas);

            if ($dataMinimaPorIdade->gt($data)) {
                return [
                    'data' => $dataMinimaPorIdade,
                    'rotulo' => 'Dose adicional',
                    'regra_texto' => "Dose adicional — a dose final da série não pode ser aplicada antes de {$protocolo->idade_minima_dose_final_semanas} semanas de idade (RN33).",
                    'tipo' => 'dose_adicional',
                ];
            }
        }

        return [
            'data' => $data,
            'rotulo' => $rotulo,
            'regra_texto' => "Intervalo de {$intervalo} dias entre as doses — protocolo {$protocolo->versao}.",
            'tipo' => 'serie',
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>, 2: ?array<string, string>}
     *                                                                                              próxima dose, estação prevista e — quando o protocolo
     *                                                                                              não prevê revacinação — a situação de série concluída
     */
    private function calcularProximaDose(Animal $animal, Collection $doses, ProtocoloVacinal $protocolo, Vacinacao $ultimaExata): array
    {
        $previsao = $this->preverDoseSeguinte(
            $protocolo,
            $ultimaExata->aplicado_em,

            // Onde a série está, segundo quem a conduz. A dose que ancora o
            // cálculo é a última de data exata, e é a ordem **dela** que diz
            // quantas doses valem como aplicadas — não o tamanho do grupo, que
            // conta também o pregresso de data imprecisa e as aplicações que o
            // profissional declarou fora da série ao reiniciá-la (RF27, RN36).
            // Sem ordem declarada — todo pregresso, e todo registro anterior a
            // V07 — a contagem do grupo continua sendo a melhor resposta.
            $ultimaExata->ordem_dose ?? $doses->count(),

            $animal->nascimento_em,
        );

        // Série concluída não é ausência de previsão: é a previsão. Devolver
        // `proxima_dose` nula é o sinal que as seis telas já tratam com `?.` ou
        // `v-if`, e a situação própria impede que o grupo caia no fallback de
        // "sem data suficiente para calcular" — que seria mentira, e que na
        // carteira do tutor se lê como falha do sistema.
        if ($previsao['data'] === null) {
            return [null, null, [
                'tipo' => 'em-dia',
                'texto' => 'série concluída',
                'texto_curto' => 'concluída',
            ]];
        }

        $situacao = $this->calcularSituacao($previsao['data']);

        $proximaDose = [
            'prevista_para' => $previsao['data']->toDateString(),
            'situacao' => $situacao['pill'],
            'regra_texto' => $previsao['regra_texto'],

            // Qual dose é a que está sendo prevista. O trilho da carteira (T05)
            // já mostrava isso na estação; a tabela de V02 precisa do mesmo
            // dado em coluna própria, e tirá-lo daqui evita que a rechamada
            // rotule a dose por uma segunda regra.
            'rotulo' => $previsao['rotulo'],
            'rotulo_curto' => $this->encurtarRotulo($previsao['rotulo']),

            // Qual regra do protocolo respondeu, em chave e não em texto. V07
            // precisa distinguir a dose adicional de RN33 das demais para acender
            // o alerta próprio dela, e reconhecê-la pelo rótulo exibido faria a
            // decisão clínica depender de uma cadeia de caracteres de interface.
            'tipo' => $previsao['tipo'],
        ];

        $estacao = [
            'tipo' => $previsao['tipo'] === 'dose_adicional' ? 'extra' : $situacao['tipo_estacao'],
            'origem' => null,
            'rotulo' => $previsao['rotulo'],
            'data' => $previsao['data']->toDateString(),
            'data_aproximada' => false,
        ];

        return [$proximaDose, $estacao, null];
    }

    /**
     * @return array{tipo_estacao: string, pill: array{tipo: string, texto: string, texto_curto: string}}
     */
    private function calcularSituacao(Carbon $dataPrevista): array
    {
        $hoje = Carbon::today();

        // A data prevista nasce de `aplicado_em`, que carrega a hora da
        // aplicação, e é publicada como data pura (`toDateString()`). Sem zerar
        // a hora aqui, a contagem compara 20/07 09:30 com a meia-noite de hoje
        // e trunca um dia: a mesma dose aparecia como "há 27 dias" na carteira
        // e "28 dias" na coluna de atraso de V02. Quem conta dias entre datas
        // conta de meia-noite a meia-noite.
        $dataPrevista = $dataPrevista->copy()->startOfDay();

        if ($dataPrevista->lt($hoje)) {
            $diasAtraso = (int) $dataPrevista->diffInDays($hoje);
            $textoDias = "há {$diasAtraso} ".($diasAtraso === 1 ? 'dia' : 'dias');

            return [
                'tipo_estacao' => 'atrasada',
                'pill' => [
                    'tipo' => 'atrasada',
                    'texto' => "atrasada · {$textoDias}",
                    'texto_curto' => $textoDias,
                ],
            ];
        }

        $diasRestantes = (int) $hoje->diffInDays($dataPrevista);

        if ($diasRestantes <= self::JANELA_PROXIMA_DIAS) {
            $textoDias = match (true) {
                $diasRestantes === 0 => 'hoje',
                $diasRestantes === 1 => 'amanhã',
                default => "em {$diasRestantes} dias",
            };

            return [
                'tipo_estacao' => 'proxima',
                'pill' => ['tipo' => 'proxima', 'texto' => "próxima · {$textoDias}", 'texto_curto' => $textoDias],
            ];
        }

        return [
            'tipo_estacao' => 'prevista',
            'pill' => ['tipo' => 'em-dia', 'texto' => 'em dia', 'texto_curto' => 'em dia'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function montarAplicacao(Vacinacao $dose, string $rotulo): array
    {
        return [
            'id' => $dose->id,
            'rotulo' => $rotulo,
            'data' => $dose->aplicado_em?->toDateString(),
            'data_aproximada' => $dose->data_aproximada,
            'origem' => $dose->origem,
            'fabricante' => $dose->fabricante,
            'lote' => $dose->lote,
            'validade' => $dose->validade?->toDateString(),
            'via_administracao' => $dose->via_administracao,
            'local_aplicacao' => $dose->local_aplicacao,
            'validade_expirada_confirmada' => $dose->validade_expirada_confirmada,
            'aplicador' => $dose->origem === 'profissional' ? [
                'nome' => $dose->aplicador_nome,
                'crmv' => $dose->aplicador_crmv,
                'prestador' => $dose->prestador?->nome,
            ] : null,
            'lancado_por' => $dose->origem === 'pregresso' ? [
                'nome' => $dose->lancadoPor?->name,
                'em' => $dose->created_at->toDateString(),
            ] : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $grupos
     * @return array<string, int>
     */
    private function montarResumo(array $grupos, Collection $todasVacinacoes): array
    {
        $porTipo = collect($grupos)->countBy(fn (array $grupo) => $grupo['situacao']['tipo']);

        return [
            'em_dia' => $porTipo->get('em-dia', 0),
            'proxima' => $porTipo->get('proxima', 0),
            'atrasada' => $porTipo->get('atrasada', 0),
            // Contagem de registros, não de grupos: RN24 marca cada aplicação
            // pregressa individualmente, e é isso que o selo do topo anuncia.
            'nao_verificadas' => $todasVacinacoes->filter(fn (Vacinacao $v) => $v->pregresso())->count(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $grupos
     * @return array<int, array<string, mixed>>
     */
    private function montarProximasDoses(array $grupos): array
    {
        return collect($grupos)
            ->filter(fn (array $grupo) => $grupo['proxima_dose'] !== null)
            ->sortBy(fn (array $grupo) => $grupo['proxima_dose']['prevista_para'])
            ->map(fn (array $grupo) => [
                'imunobiologico' => $grupo['imunobiologico']['nome'],
                // A chave estável do imunobiológico acompanha o nome porque V02
                // filtra por ela (RF49): o nome comercial é o que se lê, mas
                // não é o que se compara.
                'imunobiologico_chave' => $grupo['imunobiologico']['chave'],
                'prevista_para' => $grupo['proxima_dose']['prevista_para'],
                'situacao' => $grupo['proxima_dose']['situacao'],
                'regra_texto' => $grupo['proxima_dose']['regra_texto'],
                'rotulo' => $grupo['proxima_dose']['rotulo'],
                'rotulo_curto' => $grupo['proxima_dose']['rotulo_curto'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{tipo: string, texto: string}  $situacao
     */
    private function pesoDeOrdenacao(array $situacao): int
    {
        return match ($situacao['tipo']) {
            'atrasada' => 0,
            'proxima' => 1,
            'nao-verificada' => 2,
            default => 3,
        };
    }

    /**
     * O mesmo rótulo na largura de uma coluna de tabela. "Reforço a cada 3
     * anos" diz, na carteira, quando a próxima virá depois desta; na coluna
     * "Dose" de V02, ao lado da data prevista e dos dias de atraso, a
     * periodicidade já está dita duas vezes e só sobra a palavra que importa.
     */
    private function encurtarRotulo(string $rotulo): string
    {
        return match (true) {
            str_starts_with($rotulo, 'Reforço') => 'reforço',
            $rotulo === 'Primeiro reforço' => 'reforço',
            $rotulo === 'Série concluída' => 'concluída',
            $rotulo === 'Dose adicional' => 'dose adicional',
            default => str_replace(' · final da série', '', $rotulo),
        };
    }

    private function humanizarPeriodicidade(int $meses): string
    {
        if ($meses % 12 !== 0) {
            return "Reforço a cada {$meses} meses";
        }

        $anos = intdiv($meses, 12);

        return $anos === 1 ? 'Reforço anual' : "Reforço a cada {$anos} anos";
    }

    /**
     * O mesmo prazo como complemento de frase — "6 meses", "1 ano" — para o
     * texto do primeiro reforço, que precisa dizer o prazo dele e a
     * periodicidade seguinte na mesma sentença.
     */
    private function prazoEmTexto(int $meses): string
    {
        if ($meses % 12 !== 0) {
            return "{$meses} ".($meses === 1 ? 'mês' : 'meses');
        }

        $anos = intdiv($meses, 12);

        return "{$anos} ".($anos === 1 ? 'ano' : 'anos');
    }
}
