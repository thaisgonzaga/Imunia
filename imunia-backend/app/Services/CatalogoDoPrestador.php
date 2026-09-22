<?php

namespace App\Services;

use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A04 — o acervo próprio de vacinas de um prestador (RF23, RN30).
 *
 * Duas coisas justificam esta classe existir ao lado de X01. A primeira é de
 * alcance: o que a clínica cadastra vale só para ela, e o que a plataforma
 * mantém vale para todas — são acervos com donos diferentes, e o dono decide
 * quem edita. A segunda é de vocabulário: X02 pergunta ao administrador da
 * plataforma dez parâmetros de diretriz, porque é disso que ele responde; aqui
 * se pergunta a quem atende quantas doses são e de quanto em quanto tempo
 * repetir, que é o que ele sabe. A tradução de uma coisa na outra é
 * `parametrosDoAgendamento()`, e é o único lugar onde ela acontece.
 */
class CatalogoDoPrestador
{
    /**
     * A clínica não classifica em essencial ou não essencial: isso é leitura de
     * diretriz (RN31), e a diretriz não examinou o que ela cadastrou.
     */
    private const CLASSIFICACAO_PADRAO = 'nao_essencial';

    public function __construct(private readonly CalendarioVacinalService $calendario) {}

    /**
     * Os dois acervos, separados. Não é uma lista com coluna de origem: a
     * separação é o conteúdo, e misturá-los faria o cadeado do oficial parecer
     * um estado transitório em vez do que ele é.
     *
     * @return array{oficiais: list<array<string, mixed>>, proprias: list<array<string, mixed>>}
     */
    public function listar(Prestador $prestador): array
    {
        $itens = Imunobiologico::query()
            ->with('protocolos')
            ->where(fn ($consulta) => $consulta
                ->where('prestador_id', $prestador->id)
                // O item da plataforma que foi inativado não é escolha nem
                // referência: some da lista como some do formulário de V07.
                ->orWhere(fn ($oficial) => $oficial->whereNull('prestador_id')->where('ativo', true)))
            ->orderBy('nome_comercial')
            ->get();

        return [
            'oficiais' => $itens->filter->daPlataforma()
                ->map(fn (Imunobiologico $item) => $this->representar($item))
                ->values()
                ->all(),

            // A própria inativada continua na lista, com a ação de reativar —
            // é a clínica que responde por ela, e sumir com o item faria a
            // inativação parecer exclusão (RF23b).
            'proprias' => $itens->reject->daPlataforma()
                ->sortByDesc('ativo')
                ->map(fn (Imunobiologico $item) => $this->representar($item))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados  já validados por SalvarVacinaDoPrestadorRequest
     */
    public function criar(Prestador $prestador, array $dados): Imunobiologico
    {
        return DB::transaction(function () use ($prestador, $dados) {
            $imunobiologico = new Imunobiologico($this->cadastrais($dados));

            // `prestador_id` não é campo de formulário, e por isso não está no
            // `#[Fillable]`: de quem é o item é decisão do servidor. A chave
            // leva o prestador por prefixo para que o acervo próprio tenha
            // espaço de nomes reservado — sem isso, "vacina-da-casa" de duas
            // clínicas viraria "vacina-da-casa" e "vacina-da-casa-2", e nenhuma
            // das duas se explicaria sozinha no filtro de V02 (RF49).
            $imunobiologico->forceFill([
                'prestador_id' => $prestador->id,
                'chave' => Imunobiologico::chaveDisponivel(
                    'p'.$prestador->id.'-'.Str::slug($dados['nome_comercial']),
                ),
            ])->save();

            $this->gravarAgendamento($imunobiologico, $dados);

            return $imunobiologico->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    public function atualizar(Imunobiologico $imunobiologico, array $dados): Imunobiologico
    {
        return DB::transaction(function () use ($imunobiologico, $dados) {
            // A chave não se reescreve, pelo mesmo motivo de X01: é
            // identificador estável (RN30), e o nome comercial é que se
            // corrige.
            $imunobiologico->update($this->cadastrais($dados));

            $this->gravarAgendamento($imunobiologico, $dados);

            return $imunobiologico->fresh();
        });
    }

    public function inativar(Imunobiologico $imunobiologico): void
    {
        $imunobiologico->update(['ativo' => false]);
    }

    public function reativar(Imunobiologico $imunobiologico): void
    {
        $imunobiologico->update(['ativo' => true]);
    }

    /**
     * O que o tutor receberia, se a vacina fosse aplicada hoje — sem gravar
     * nada.
     *
     * As datas saem de `CalendarioVacinalService::preverDoseSeguinte()`, o
     * mesmo método que a carteira e o simulador de X02 chamam, e pelo mesmo
     * argumento de RNF01: uma tela que existe para mostrar o cálculo não pode
     * calcular por conta própria — estaria conferindo a si mesma. O protocolo
     * é montado em memória e nunca chega ao banco; `preverDoseSeguinte()` só lê
     * atributos, e um modelo sem identificador serve.
     *
     * @param  array<string, mixed>  $dados
     * @return array{passos: list<array<string, mixed>>, resumo: string}
     */
    public function previa(array $dados): array
    {
        $protocolo = new ProtocoloVacinal($this->parametrosDoAgendamento($dados));
        $hoje = Carbon::today();

        $passos = [[
            'rotulo' => $this->calendario->rotuloDaDose(1, $protocolo),
            'data' => $hoje->toDateString(),
            'regra' => 'A aplicação de hoje, que abre a série.',
        ]];

        $ultima = $hoje;
        $doses = $protocolo->numero_doses_serie_primaria;

        for ($ordem = 1; $ordem < $doses; $ordem++) {
            $previsao = $this->calendario->preverDoseSeguinte($protocolo, $ultima, $ordem);

            $passos[] = [
                'rotulo' => $previsao['rotulo'],
                'data' => $previsao['data']?->toDateString(),
                'regra' => $previsao['regra_texto'],
            ];

            $ultima = $previsao['data'] ?? $ultima;
        }

        $reforco = $this->calendario->preverDoseSeguinte($protocolo, $ultima, $doses);

        $passos[] = [
            'rotulo' => $reforco['rotulo'],
            'data' => $reforco['data']?->toDateString(),
            'regra' => $reforco['regra_texto'],
        ];

        return ['passos' => $passos, 'resumo' => $this->agendamentoEmTexto($protocolo)];
    }

    /**
     * A tradução: o que o formulário pergunta em vocabulário de quem atende,
     * nas colunas que o motor lê.
     *
     * Quatro colunas de propósito não são perguntadas, e a ausência é a
     * decisão:
     *
     * - as duas idades mínimas ficam nulas porque são leitura de diretriz sobre
     *   anticorpos maternos (RN33), e não parâmetro de estabelecimento. A da
     *   dose final importa mais: é ela que faria `preverDoseSeguinte()` agendar
     *   a dose adicional de RN33 sobre uma vacina que a WSAVA nunca examinou;
     * - `limite_atraso_dias` e `conduta_apos_limite` ficam no padrão do
     *   esquema. O limite é o ponto em que o atraso vira pergunta clínica, e a
     *   clínica não precisa opinar sobre isso antes de ter o caso na frente;
     *   a conduta, mesmo gravada, é sugestão e nunca impedimento (RN36).
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function parametrosDoAgendamento(array $dados): array
    {
        $doses = (int) $dados['doses_primeira_vez'];

        // Janela degenerada, e não a janela de duas a quatro semanas de RN34: a
        // clínica declarou um prazo, não a faixa que uma diretriz admite.
        // `intervaloPrevistoDias()` tira o ponto médio, que aqui é o próprio
        // valor. Dose única grava zero, como a antirrábica do catálogo.
        $intervalo = $doses === 1 ? 0 : ((int) $dados['intervalo_semanas']) * 7;

        return [
            'numero_doses_serie_primaria' => $doses,
            'intervalo_minimo_dias' => $intervalo,
            'intervalo_maximo_dias' => $intervalo,
            'idade_minima_primeira_dose_semanas' => null,
            'idade_minima_dose_final_semanas' => null,
            'reforco_inicial_meses' => $this->reforcoInicialEmMeses($dados, $doses),
            'periodicidade_revacinacao_meses' => $this->periodicidadeEmMeses($dados),
            'limite_atraso_dias' => 30,
            'conduta_apos_limite' => ProtocoloVacinal::PROSSEGUIR,

            // A clínica não distinguiu filhote de adulto, e afirmar uma série
            // diferente para o adulto sem histórico seria inventar diretriz em
            // nome dela.
            'doses_adulto_sem_historico' => $doses,
        ];
    }

    /**
     * O agendamento numa frase — a mesma para a lista de A04, para a prévia do
     * formulário e para o que a tela do veterinário mostra ao lado do item.
     */
    public function agendamentoEmTexto(?ProtocoloVacinal $protocolo): string
    {
        if ($protocolo === null) {
            return 'Sem agendamento definido';
        }

        $doses = $protocolo->numero_doses_serie_primaria;

        $serie = $doses === 1
            ? 'Dose única'
            : "{$doses} doses a cada ".$protocolo->intervaloPrevistoDias().' dias';

        if ($protocolo->semRevacinacao()) {
            return "{$serie}, sem revacinação";
        }

        $meses = $protocolo->periodicidade_revacinacao_meses;

        // "a cada 1 ano" é o que a conversão devolveria, e ninguém fala assim.
        $periodicidade = $meses === 12 ? 'anual' : 'a cada '.$this->emTexto($meses);

        if ($protocolo->reforco_inicial_meses === null) {
            return "{$serie} · reforço {$periodicidade}";
        }

        return "{$serie} · primeiro reforço em ".$this->emTexto($protocolo->reforco_inicial_meses)
            ." · depois, {$periodicidade}";
    }

    /**
     * A linha de parâmetros nunca é alterada depois de criada: gravar um
     * agendamento é criar a linha seguinte.
     *
     * Alguma `vacinacao` pode tê-la congelado em `protocolo_vacinal_id`, e
     * mudar-lhe um número recalcularia, em silêncio, uma data que já foi
     * mostrada ao tutor — que é exatamente o que RN32 proíbe. Sendo sempre
     * append, a garantia deixa de ser condição avaliada em tempo de execução e
     * passa a ser propriedade da tabela: nenhuma linha muda, logo nenhuma data
     * emitida pode mudar. É o mesmo raciocínio de `PublicacaoDeProtocoloService`,
     * onde publicar uma versão é criar as linhas dela, e o mesmo de RN26 no
     * registro clínico, onde corrigir é criar a versão nova.
     *
     * `protocoloVigente()` pega a de maior identificador; as anteriores
     * continuam explicando o que explicaram.
     *
     * @param  array<string, mixed>  $dados
     */
    private function gravarAgendamento(Imunobiologico $imunobiologico, array $dados): void
    {
        ProtocoloVacinal::create([
            'imunobiologico_id' => $imunobiologico->id,

            // Sem versão, e é isto que mantém o agendamento fora do alcance da
            // plataforma: o rascunho de X02 não o copia, a conferência de
            // incoerências não o examina e a publicação da revisão seguinte da
            // WSAVA não o tira de vigência.
            'versao_protocolo_id' => null,

            ...$this->parametrosDoAgendamento($dados),
        ]);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function cadastrais(array $dados): array
    {
        return [
            'nome_comercial' => $dados['nome_comercial'],

            // O formulário não pede denominação técnica: quem cadastra a vacina
            // que usa não tem por que traduzi-la para nomenclatura de bula. O
            // campo existe no esquema porque X01 o preenche, e aqui repete o
            // comercial para que a tela que exibe os dois não mostre um vazio.
            'nome_tecnico' => $dados['nome_comercial'],
            'fabricante' => $dados['fabricante'],
            'agentes_cobertos' => $dados['agentes_cobertos'] ?? 'não informado',
            'especie_destino' => $dados['especie_destino'],
            'classificacao' => self::CLASSIFICACAO_PADRAO,
            'via_administracao_usual' => $dados['via_administracao_usual'],
            'ativo' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function periodicidadeEmMeses(array $dados): ?int
    {
        // Nulo é "dose única, sem revacinação" — a resposta, e não a falta dela.
        if (! $dados['repete']) {
            return null;
        }

        return $this->converterParaMeses(
            (int) $dados['periodicidade_valor'],
            (string) $dados['periodicidade_unidade'],
        );
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function reforcoInicialEmMeses(array $dados, int $doses): ?int
    {
        // Nulo já significa "igual à periodicidade" em `primeiroReforcoMeses()`,
        // e é o que vale quando a clínica não distinguiu os dois prazos — ou
        // quando não há série a completar antes do primeiro reforço.
        if ($doses === 1 || ! $dados['repete'] || ! ($dados['primeiro_reforco_diferente'] ?? false)) {
            return null;
        }

        return $this->converterParaMeses(
            (int) $dados['primeiro_reforco_valor'],
            (string) $dados['primeiro_reforco_unidade'],
        );
    }

    private function converterParaMeses(int $valor, string $unidade): int
    {
        return $unidade === 'anos' ? $valor * 12 : $valor;
    }

    private function emTexto(int $meses): string
    {
        if ($meses % 12 !== 0) {
            return "{$meses} ".($meses === 1 ? 'mês' : 'meses');
        }

        $anos = intdiv($meses, 12);

        return "{$anos} ".($anos === 1 ? 'ano' : 'anos');
    }

    /**
     * @return array<string, mixed>
     */
    private function representar(Imunobiologico $imunobiologico): array
    {
        $protocolo = $imunobiologico->protocoloVigente();

        return [
            'id' => $imunobiologico->id,
            'chave' => $imunobiologico->chave,
            'nome_comercial' => $imunobiologico->nome_comercial,
            'nome_tecnico' => $imunobiologico->nome_tecnico,
            'fabricante' => $imunobiologico->fabricante,
            'agentes_cobertos' => $imunobiologico->agentes_cobertos,
            'especie_destino' => $imunobiologico->especie_destino,
            'classificacao' => $imunobiologico->classificacao,
            'via_administracao_usual' => $imunobiologico->via_administracao_usual,
            'ativo' => $imunobiologico->ativo,
            'agendamento' => $this->agendamentoEmTexto($protocolo),

            // O cadeado da tela sai daqui, e não de um `v-if` sobre a origem:
            // a interface desenha o que o servidor disse, e a recusa continua
            // sendo do servidor.
            'bloqueado' => $imunobiologico->daPlataforma(),

            // As respostas do formulário, remontadas a partir das colunas, para
            // que abrir a edição não exija adivinhar o que foi respondido.
            'agendamento_respostas' => $imunobiologico->daPlataforma() ? null : $this->respostasDe($protocolo),
        ];
    }

    /**
     * O caminho de volta de `parametrosDoAgendamento()`: das colunas para as
     * perguntas. Só faz sentido para o acervo próprio, que foi criado por essas
     * perguntas — os parâmetros da plataforma vêm de diretriz e não cabem
     * nelas.
     *
     * @return array<string, mixed>|null
     */
    private function respostasDe(?ProtocoloVacinal $protocolo): ?array
    {
        if ($protocolo === null) {
            return null;
        }

        $meses = $protocolo->periodicidade_revacinacao_meses;
        $reforco = $protocolo->reforco_inicial_meses;

        return [
            'doses_primeira_vez' => $protocolo->numero_doses_serie_primaria,
            'intervalo_semanas' => $protocolo->numero_doses_serie_primaria === 1
                ? null
                : intdiv($protocolo->intervaloPrevistoDias(), 7),
            'repete' => ! $protocolo->semRevacinacao(),
            'periodicidade_valor' => $meses === null ? null : $this->valorNaUnidade($meses),
            'periodicidade_unidade' => $meses === null ? 'anos' : $this->unidadeDe($meses),
            'primeiro_reforco_diferente' => $reforco !== null,
            'primeiro_reforco_valor' => $reforco === null ? null : $this->valorNaUnidade($reforco),
            'primeiro_reforco_unidade' => $reforco === null ? 'meses' : $this->unidadeDe($reforco),
        ];
    }

    private function unidadeDe(int $meses): string
    {
        return $meses % 12 === 0 ? 'anos' : 'meses';
    }

    private function valorNaUnidade(int $meses): int
    {
        return $meses % 12 === 0 ? intdiv($meses, 12) : $meses;
    }
}
