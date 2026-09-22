<?php

namespace App\Services;

use App\Models\ProtocoloVacinal;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Simulador do cálculo de calendário (X02). Recebe espécie, nascimento e datas
 * hipotéticas de aplicação e devolve o calendário resultante, passo a passo,
 * com a regra que produziu cada data.
 *
 * Nada aqui grava nada — não há model salvo em lugar algum deste serviço, e é
 * essa a promessa que a tela faz ao dizer "confere o resultado sem tocar em
 * dado real". As datas saem de `CalendarioVacinalService::preverDoseSeguinte()`,
 * o mesmo método que a carteira do tutor usa: RNF01 exige reprodutibilidade, e
 * uma tela que conferisse o cálculo com um cálculo próprio não conferiria nada.
 */
class SimuladorDeProtocoloService
{
    /** Os quatro casos declarados em RNF01, na ordem em que a tela os oferece. */
    public const CASOS = [
        'serie_completa' => 'série primária completa',
        'serie_com_atraso' => 'série com atraso',
        'adulto_sem_historico' => 'adulto sem histórico',
        'dose_final_antes_da_idade' => 'dose final antes da idade mínima',
    ];

    /**
     * Acima desta idade, um animal sem registro algum é tratado como adulto
     * sem histórico conhecido, e não como filhote atrasado. O corte é a idade
     * mínima da dose final quando o protocolo a declara — é ela que marca o fim
     * da janela de interferência dos anticorpos maternos (RN33).
     */
    private const SEMANAS_ADULTO_PADRAO = 52;

    public function __construct(private CalendarioVacinalService $calendario) {}

    /**
     * As entradas de cada caso, calculadas a partir dos próprios parâmetros do
     * protocolo — e não fixadas em datas de almanaque. Um caso escrito com
     * datas fixas deixaria de exercitar o que promete assim que alguém mudasse
     * o intervalo da série.
     *
     * @return array<string, array{rotulo: string, nascimento_em: string|null, doses: array<int, string>}>
     */
    public function casos(ProtocoloVacinal $protocolo): array
    {
        $hoje = Carbon::today();
        $intervalo = max($protocolo->intervaloPrevistoDias(), 1);
        $idadePrimeira = $protocolo->idade_minima_primeira_dose_semanas ?? 6;
        $numeroDoses = max($protocolo->numero_doses_serie_primaria, 1);

        // Filhote de oito meses: a série cabe inteira no passado e o reforço
        // ainda cai no futuro, que é o que torna o caso legível na tela.
        $nascimento = $hoje->copy()->subMonths(8);

        // No caso "completo", a primeira dose é a mais cedo que ainda faz a
        // série *terminar* na idade mínima da dose final — caso contrário este
        // caso mostraria a dose adicional de RN33, que é o caso 4.
        $semanasPrimeira = (int) ceil(max(
            $idadePrimeira,
            ($protocolo->idade_minima_dose_final_semanas ?? $idadePrimeira) - ($numeroDoses - 1) * $intervalo / 7,
        ));

        $completa = $this->serieAPartirDe($nascimento->copy()->addWeeks($semanasPrimeira), $numeroDoses, $intervalo);

        $comAtraso = $completa;
        if (count($comAtraso) > 1) {
            $atraso = $protocolo->limite_atraso_dias * 2 + 1;
            $comAtraso[count($comAtraso) - 1] = Carbon::parse(end($comAtraso))->addDays($atraso)->toDateString();
        }

        // Nasce cedo o bastante para que a série termine antes da idade mínima
        // da dose final: é o caso que RN33 existe para resolver.
        $nascimentoCurto = $hoje->copy()->subMonths(6);

        return [
            'serie_completa' => [
                'rotulo' => self::CASOS['serie_completa'],
                'nascimento_em' => $nascimento->toDateString(),
                'doses' => $completa,
            ],
            'serie_com_atraso' => [
                'rotulo' => self::CASOS['serie_com_atraso'],
                'nascimento_em' => $nascimento->toDateString(),
                'doses' => $comAtraso,
            ],
            'adulto_sem_historico' => [
                'rotulo' => self::CASOS['adulto_sem_historico'],
                'nascimento_em' => $hoje->copy()->subYears(4)->toDateString(),
                'doses' => [],
            ],
            'dose_final_antes_da_idade' => [
                'rotulo' => self::CASOS['dose_final_antes_da_idade'],
                'nascimento_em' => $nascimentoCurto->toDateString(),
                'doses' => $this->serieAPartirDe($nascimentoCurto->copy()->addWeeks($idadePrimeira), $numeroDoses, $intervalo),
            ],
        ];
    }

    /**
     * @param  Collection<int, Carbon>  $aplicadas  datas hipotéticas, em ordem
     * @return array<string, mixed>
     */
    public function simular(ProtocoloVacinal $protocolo, ?Carbon $nascimentoEm, Collection $aplicadas): array
    {
        $hoje = Carbon::today();
        $serie = $aplicadas->sort()->values();

        $adultoSemHistorico = $serie->isEmpty() && $this->ehAdulto($protocolo, $nascimentoEm, $hoje);
        $aplicavel = $this->protocoloAplicavel($protocolo, $adultoSemHistorico);
        $totalSerie = max($aplicavel->numero_doses_serie_primaria, 1);

        $passos = [];
        $projetadas = $serie->isEmpty();

        // Sem dose alguma registrada, a primeira dose é a de hoje: é o que o
        // profissional veria ao atender o animal agora.
        $primeira = $serie->first() ?? $hoje->copy();
        $passos[] = $this->passoDaPrimeiraDose($aplicavel, $nascimentoEm, $primeira, $projetadas, $adultoSemHistorico, $totalSerie);

        $ultima = $primeira;

        for ($indice = 1; $indice < $serie->count(); $indice++) {
            $previsao = $this->calendario->preverDoseSeguinte($aplicavel, $ultima, $indice, $nascimentoEm);
            $aplicada = $serie[$indice];

            // Protocolo sem revacinação não prevê data para esta dose, e sem
            // data prevista não há atraso a medir. Não acontece com as linhas
            // versionadas, onde a periodicidade é obrigatória — o guard existe
            // porque a coluna aceita nulo desde A04.
            if ($previsao['data'] === null) {
                $passos[] = $this->passoDeSerieConcluida($previsao);
                $ultima = $aplicada;

                continue;
            }

            $atraso = (int) $previsao['data']->copy()->startOfDay()->diffInDays($aplicada->copy()->startOfDay(), false);

            $passos[] = $this->passoDeDoseAplicada($aplicavel, $previsao, $aplicada, $atraso, $indice);

            if ($atraso > 0) {
                $passos[] = $this->passoDeAtraso($aplicavel, $atraso);
            }

            $ultima = $aplicada;
        }

        // RN33 — a dose final já aplicada antes da idade mínima não se corrige
        // no passado: agenda-se uma dose a mais adiante, e o reforço passa a
        // contar dela.
        if ($serie->count() >= $totalSerie) {
            [$passoAdicional, $ultima] = $this->passoDeDoseAdicional($aplicavel, $nascimentoEm, $serie, $totalSerie, $ultima);

            if ($passoAdicional !== null) {
                $passos[] = $passoAdicional;
            }
        }

        for ($ordem = max($serie->count(), 1); $ordem < $totalSerie; $ordem++) {
            $previsao = $this->calendario->preverDoseSeguinte($aplicavel, $ultima, $ordem, $nascimentoEm);

            $passos[] = [
                'rotulo' => $previsao['rotulo'],
                'data' => $previsao['data']->toDateString(),
                'valor_texto' => null,
                'regra' => $previsao['regra_texto'],
                'tom' => $previsao['tipo'] === 'dose_adicional' ? 'extra' : 'previsto',
            ];

            $ultima = $previsao['data'];
        }

        $reforco = $this->calendario->preverDoseSeguinte($aplicavel, $ultima, max($serie->count(), $totalSerie), $nascimentoEm);

        // Este passo roda sempre, mesmo com a série vazia, e é por isso o
        // primeiro a encontrar um protocolo sem revacinação.
        $passos[] = $reforco['data'] === null ? $this->passoDeSerieConcluida($reforco) : [
            'rotulo' => $reforco['rotulo'],
            'data' => $reforco['data']->toDateString(),
            'valor_texto' => null,
            'regra' => $reforco['regra_texto'],
            'tom' => 'reforco',
        ];

        return [
            'entradas' => [
                'nascimento_em' => $nascimentoEm?->toDateString(),
                'doses' => $serie->map(fn (Carbon $data) => $data->toDateString())->all(),
            ],
            'serie_considerada' => $totalSerie,
            'adulto_sem_historico' => $adultoSemHistorico,
            'passos' => $passos,
        ];
    }

    /**
     * @param  array<int, string>  $doses
     * @return array<int, string>
     */
    private function serieAPartirDe(Carbon $primeira, int $numeroDoses, int $intervalo): array
    {
        $doses = [$primeira->toDateString()];
        $data = $primeira->copy();

        for ($ordem = 1; $ordem < $numeroDoses; $ordem++) {
            $data = $data->copy()->addDays($intervalo);
            $doses[] = $data->toDateString();
        }

        return $doses;
    }

    private function ehAdulto(ProtocoloVacinal $protocolo, ?Carbon $nascimentoEm, Carbon $referencia): bool
    {
        if ($nascimentoEm === null) {
            return false;
        }

        $corte = $protocolo->idade_minima_dose_final_semanas ?? self::SEMANAS_ADULTO_PADRAO;

        return $this->semanasEntre($nascimentoEm, $referencia) >= $corte;
    }

    /**
     * O protocolo do adulto sem histórico é o mesmo, com a série que a diretriz
     * prevê para ele. A cópia não é persistida em lugar algum — o simulador não
     * escreve, e o parâmetro do banco continua sendo o do filhote.
     */
    private function protocoloAplicavel(ProtocoloVacinal $protocolo, bool $adultoSemHistorico): ProtocoloVacinal
    {
        if (! $adultoSemHistorico) {
            return $protocolo;
        }

        $copia = clone $protocolo;
        $copia->numero_doses_serie_primaria = max($protocolo->doses_adulto_sem_historico, 1);

        return $copia;
    }

    /**
     * @return array<string, mixed>
     */
    private function passoDaPrimeiraDose(
        ProtocoloVacinal $protocolo,
        ?Carbon $nascimentoEm,
        Carbon $data,
        bool $projetada,
        bool $adultoSemHistorico,
        int $totalSerie,
    ): array {
        $minimo = $protocolo->idade_minima_primeira_dose_semanas;

        $regra = match (true) {
            $adultoSemHistorico => "Adulto sem histórico · série de {$totalSerie} ".($totalSerie === 1 ? 'dose' : 'doses'),
            $nascimentoEm === null => 'Idade desconhecida · a idade mínima da primeira dose não pôde ser conferida',
            default => $this->regraDaPrimeiraDose($this->semanasEntre($nascimentoEm, $data), $minimo),
        };

        return [
            'rotulo' => '1ª dose',
            'data' => $data->toDateString(),
            'valor_texto' => null,
            'regra' => $regra,
            'tom' => $projetada ? 'previsto' : 'neutro',
        ];
    }

    /**
     * O passo que fecha o quadro quando o protocolo não prevê revacinação.
     * `tom: 'ignorado'` é o mesmo da paleta que a dose adicional já usa para
     * dizer "esta linha não é uma data a cumprir".
     *
     * @param  array{data: ?Carbon, rotulo: string, regra_texto: string, tipo: string}  $previsao
     * @return array<string, mixed>
     */
    private function passoDeSerieConcluida(array $previsao): array
    {
        return [
            'rotulo' => $previsao['rotulo'],
            'data' => null,
            'valor_texto' => 'sem revacinação',
            'regra' => $previsao['regra_texto'],
            'tom' => 'ignorado',
        ];
    }

    private function regraDaPrimeiraDose(int $semanas, ?int $minimo): string
    {
        if ($minimo === null) {
            return "Aplicada com {$semanas} semanas · o protocolo não fixa idade mínima";
        }

        return $semanas >= $minimo
            ? "Aplicada com {$semanas} semanas · idade mínima de {$minimo} semanas atendida"
            : "Aplicada com {$semanas} semanas · abaixo da idade mínima de {$minimo} semanas";
    }

    /**
     * A coluna "Regra" da tela é estreita e se repete a cada linha, então ela
     * diz o que mudou de uma dose para a outra — e não a versão do protocolo,
     * que o rodapé do simulador já anuncia uma vez para o quadro inteiro. A
     * *data* continua vindo de `preverDoseSeguinte()`; o que se escreve aqui é
     * a justificativa dela, não um segundo cálculo.
     *
     * @param  array{data: Carbon, rotulo: string, regra_texto: string, tipo: string}  $previsao
     * @return array<string, mixed>
     */
    private function passoDeDoseAplicada(
        ProtocoloVacinal $protocolo,
        array $previsao,
        Carbon $aplicada,
        int $atraso,
        int $indice,
    ): array {
        $ordem = $indice + 1;
        $anterior = $indice === 1 ? 'anterior' : "{$indice}ª";

        $base = $previsao['tipo'] === 'dose_adicional'
            ? "Prevista para {$protocolo->idade_minima_dose_final_semanas} semanas de idade (RN33)"
            : $protocolo->intervaloPrevistoDias()." dias após a {$anterior}";

        if ($atraso === 0) {
            return [
                'rotulo' => "{$ordem}ª dose",
                'data' => $aplicada->toDateString(),
                'valor_texto' => null,
                'regra' => "{$base} · intervalo da série cumprido",
                'tom' => 'neutro',
            ];
        }

        if ($atraso < 0) {
            return [
                'rotulo' => "{$ordem}ª dose",
                'data' => $aplicada->toDateString(),
                'valor_texto' => null,
                'regra' => "{$base} · aplicada ".abs($atraso).' dias antes do previsto',
                'tom' => 'neutro',
            ];
        }

        // Quando houve atraso, a linha exibe a data *prevista* — é ela que
        // explica o atraso da linha seguinte; a data real aparece na regra.
        return [
            'rotulo' => "{$ordem}ª dose · prevista",
            'data' => $previsao['data']->toDateString(),
            'valor_texto' => null,
            'regra' => "{$base} · aplicada em ".$aplicada->format('d/m').", com {$atraso} dias de atraso",
            'tom' => 'previsto',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function passoDeAtraso(ProtocoloVacinal $protocolo, int $atraso): array
    {
        $limite = $protocolo->limite_atraso_dias;

        // RF27c, RN36 — o sistema calcula e sugere; a decisão é do profissional,
        // e nada aqui impede o registro de conduta divergente.
        $regra = $atraso > $limite
            ? "Acima do limite de {$limite} dias · conduta sugerida: {$protocolo->descricaoDaConduta()}"
            : "Dentro do limite de {$limite} dias · a série prossegue sem alteração";

        return [
            'rotulo' => 'Atraso',
            'data' => null,
            'valor_texto' => $atraso.($atraso === 1 ? ' dia' : ' dias'),
            'regra' => $regra,
            'tom' => $atraso > $limite ? 'atraso' : 'neutro',
        ];
    }

    /**
     * @param  Collection<int, Carbon>  $serie
     * @return array{0: array<string, mixed>|null, 1: Carbon}
     */
    private function passoDeDoseAdicional(
        ProtocoloVacinal $protocolo,
        ?Carbon $nascimentoEm,
        Collection $serie,
        int $totalSerie,
        Carbon $ultima,
    ): array {
        $minimo = $protocolo->idade_minima_dose_final_semanas;

        if ($minimo === null || $nascimentoEm === null || $totalSerie < 2) {
            return [null, $ultima];
        }

        $doseFinal = $serie[$totalSerie - 1];
        $idadeNaFinal = $this->semanasEntre($nascimentoEm, $doseFinal);

        if ($idadeNaFinal >= $minimo) {
            return [[
                'rotulo' => 'Dose adicional',
                'data' => null,
                'valor_texto' => 'não se aplica',
                'regra' => "Dose final aplicada com {$idadeNaFinal} semanas · acima da idade mínima de {$minimo}",
                'tom' => 'ignorado',
            ], $ultima];
        }

        $dataAdicional = $nascimentoEm->copy()->addWeeks($minimo);
        $semanasAMais = $minimo - $idadeNaFinal;

        return [[
            'rotulo' => 'Dose adicional',
            'data' => $dataAdicional->toDateString(),
            'valor_texto' => "+ {$semanasAMais} ".($semanasAMais === 1 ? 'semana' : 'semanas'),
            'regra' => "Dose final aplicada com {$idadeNaFinal} semanas · agendada por anticorpos de origem materna ainda circulantes (RN33)",
            'tom' => 'extra',
        ], $dataAdicional];
    }

    private function semanasEntre(Carbon $inicio, Carbon $fim): int
    {
        return (int) $inicio->copy()->startOfDay()->diffInWeeks($fim->copy()->startOfDay());
    }
}
