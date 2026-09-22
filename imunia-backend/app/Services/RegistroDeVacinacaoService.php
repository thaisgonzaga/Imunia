<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\ProtocoloVacinal;
use App\Models\RegistroDeAcesso;
use App\Models\User;
use App\Models\Vacinacao;
use App\Rules\ValidadeMesAno;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * V07 — registro profissional de aplicação de vacina (RF25, RF26, RF27).
 *
 * É a primeira escrita de registro clínico **de veterinário** do sistema: até
 * aqui, o único caminho que criava `vacinacoes` era o lançamento pregresso do
 * tutor (T09), que não é ato clínico (RN25) e deixa nulo tudo o que a Resolução
 * CFMV nº 1.321/2020 manda constar. Três decisões governam este serviço:
 *
 * 1. **A prévia passa pelo mesmo código que a carteira.** O painel de cálculo ao
 *    vivo não recalcula por conta própria: monta a coleção hipotética — as
 *    aplicações que existem mais a que está sendo redigida — e a entrega a
 *    `CalendarioVacinalService::montarCarteiraCom()`. A data que o profissional
 *    vê antes de confirmar e a que o tutor verá depois são a mesma porque saem
 *    da mesma linha de código, e não porque um teste as comparou. RF26c exige
 *    reprodutibilidade; um segundo cálculo escrito para a tela seria a segunda
 *    chance de divergir.
 *
 * 2. **Abrir a tela e recalcular são operações distintas.** `montar()` grava a
 *    linha do livro de acessos quando o animal tem registro de outro prestador
 *    (RN49); `prever()` não escreve nada. Fundir as duas faria uma vacinação de
 *    noventa segundos produzir uma dezena de linhas no livro que o tutor lê em
 *    T14, e RF53 promete esse livro legível.
 *
 * 3. **Nada aqui impede o registro.** RF27c e RN36: o serviço calcula, alerta e
 *    sugere conduta; a decisão é do médico-veterinário. Não há neste arquivo um
 *    caminho que recuse gravar por divergência clínica — só os que recusam por
 *    falta de autorização, de vínculo ou de inscrição, que são outra coisa.
 */
class RegistroDeVacinacaoService
{
    /**
     * Janela em que um segundo pedido idêntico é lido como repetição, e não
     * como segunda dose.
     *
     * Existe porque RN26 torna o registro imutável e V09 ainda não existe: um
     * toque duplo, um Enter repetido ou um reenvio da rede criariam duas
     * aplicações permanentes e indeléveis da mesma dose — num prontuário, dois
     * fatos clínicos onde houve um. A defesa do cliente (botão travado) não
     * cobre o reenvio de rede, que é justamente o caso em que ninguém tocou em
     * nada duas vezes.
     */
    private const JANELA_DUPLICIDADE_MINUTOS = 10;

    public function __construct(private readonly CalendarioVacinalService $calendario) {}

    /**
     * A abertura da tela: o que o formulário precisa saber antes da primeira
     * tecla, mais a prévia do imunobiológico já escolhido, quando V06 mandou um
     * pelo endereço (o alerta de dose atrasada leva a vacina consigo).
     *
     * @return array<string, mixed>
     */
    public function montar(
        User $profissional,
        Prestador $prestador,
        Animal $animal,
        ?string $chaveImunobiologico = null,
    ): array {
        $deOutroPrestador = $this->temRegistroDeOutroPrestador($animal, $prestador);

        // RF52b — antes de montar o que será exibido, e não depois. A prévia
        // conta ao profissional que a dose anterior venceu em tal data, e essa
        // data pode ser de registro alheio; a gravação do log é condição da
        // exibição, como em V06.
        if ($deOutroPrestador) {
            $this->registrarAcesso($profissional, $prestador, $animal);
        }

        $imunobiologico = $chaveImunobiologico !== null
            ? Imunobiologico::query()->where('chave', $chaveImunobiologico)->paraEspecieDe($animal, $prestador)->first()
            : null;

        return [
            'animal' => [
                'codigo' => $animal->codigo,
                'nome' => $animal->nome,
                'especie' => $animal->especie,
                'idade_em_meses' => $animal->idadeEmMeses(),
                'nascimento_exato' => $animal->nascimento_exato,
                'tutor' => $animal->tutor->nome,
            ],

            // RF25b — vem do acesso e não é editável: é a responsabilidade
            // técnica de quem aplica. Viaja para a tela apenas para ser exibido.
            'aplicador' => [
                'nome' => $profissional->name,
                'crmv' => $profissional->crmvEm($prestador),
            ],

            'catalogo' => $this->catalogoPara($animal, $prestador),
            'aviso_outro_prestador' => $deOutroPrestador,
            'previa' => $this->prever($animal, $prestador, $imunobiologico, null, null),
        ];
    }

    /**
     * O painel de cálculo ao vivo (RF26, RF27) — **puro**: não escreve, não
     * registra acesso, não guarda nada. É chamado a cada mudança de campo.
     *
     * @param  Carbon|null  $aplicadoEm  momento da aplicação; ausente, é agora
     * @param  int|null  $ordemDose  ordem declarada pelo profissional; ausente, é a calculada
     * @return array<string, mixed>
     */
    public function prever(
        Animal $animal,
        Prestador $prestador,
        ?Imunobiologico $imunobiologico,
        ?Carbon $aplicadoEm,
        ?int $ordemDose,
    ): array {
        $aplicadoEm ??= Carbon::now();

        if ($imunobiologico === null) {
            return [
                'imunobiologico' => null,
                'sugestoes' => null,
                'ordem_dose' => null,
                'calculo' => null,
                'alertas' => [],
            ];
        }

        $protocolo = $imunobiologico->protocoloVigente();
        $doses = $this->dosesDoGrupo($animal, $imunobiologico);

        $ordemSugerida = $this->ordemSugerida($doses);
        $ordem = $ordemDose ?? $ordemSugerida;

        $hipotetica = $this->doseHipotetica($animal, $prestador, $imunobiologico, $protocolo, $aplicadoEm, $ordem);
        $carteira = $this->calendario->montarCarteiraCom(
            $animal,
            $this->carteiraHipotetica($animal, $hipotetica),
        );

        $grupo = collect($carteira['grupos'])
            ->firstWhere('imunobiologico.chave', $imunobiologico->chave);

        return [
            'imunobiologico' => $this->apresentar($imunobiologico),
            'sugestoes' => $this->sugestoesDe($animal, $prestador, $imunobiologico),
            'ordem_dose' => [
                'sugerida' => $ordemSugerida,
                'escolhida' => $ordem,
                'opcoes' => $this->opcoesDeOrdem($protocolo, $ordemSugerida, $ordem),
            ],
            'calculo' => $this->calculoDe($grupo, $carteira, $protocolo),
            'alertas' => $this->alertas($grupo, $doses, $protocolo, $aplicadoEm, $ordem, $ordemSugerida),
        ];
    }

    /**
     * A gravação (RF25). Carimba do lado do servidor tudo o que responde pelo
     * ato — autoria, prestador, protocolo vigente e a ordem que o sistema havia
     * sugerido —, porque nenhum desses dados é do formulário: são o que o
     * sistema sabe sobre quem está gravando e sobre quando.
     *
     * @param  array<string, mixed>  $dados  já validados por RegistrarVacinacaoRequest
     */
    public function registrar(User $profissional, Prestador $prestador, Animal $animal, array $dados): Vacinacao
    {
        $imunobiologico = Imunobiologico::query()
            ->where('chave', $dados['imunobiologico'])
            ->paraEspecieDe($animal, $prestador)
            ->first();

        // A FormRequest já recusou a chave fora do alcance desta clínica, com a
        // mensagem que nomeia o motivo. Chegar aqui sem item é corrida, não erro
        // de digitação — 422 e não 404, porque o recurso pedido é o registro, e
        // ele existe: o que não vale é um dos campos dele.
        abort_if($imunobiologico === null, 422, 'Esta vacina não está no catálogo desta clínica.');

        $aplicadoEm = Carbon::parse($dados['aplicado_em']);

        // A mesma leitura que a validação fez, e não uma segunda: "04/2027" é o
        // fim de 30/04/2027, e `Carbon::parse` sozinho leria essa cadeia de
        // outra maneira.
        $validade = ValidadeMesAno::interpretar($dados['validade']);

        return DB::transaction(function () use ($profissional, $prestador, $animal, $imunobiologico, $dados, $aplicadoEm, $validade) {
            $repetida = $this->repeticaoRecente($animal, $prestador, $imunobiologico, $aplicadoEm);

            if ($repetida !== null) {
                return $repetida;
            }

            $doses = $this->dosesDoGrupo($animal, $imunobiologico);

            return $animal->vacinacoes()->create([
                'prestador_id' => $prestador->id,
                'imunobiologico_id' => $imunobiologico->id,

                // RN32 — o registro conserva a versão que valia à época. Guardar
                // a linha de parâmetros, e não o rótulo, é o que faz a
                // publicação de uma versão nova não mexer nesta data.
                'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()?->id,

                'origem' => 'profissional',
                'fabricante' => $dados['fabricante'],
                'lote' => $dados['lote'],
                'validade' => $validade,
                'via_administracao' => $dados['via_administracao'],
                'sitio_anatomico' => $dados['sitio_anatomico'] ?? null,
                'aplicado_em' => $aplicadoEm,

                // RN25 — a imprecisão de data é do pregresso. Aqui houve ato
                // clínico, com hora, e o cálculo pode ancorar-se nele.
                'data_aproximada' => false,

                'ordem_dose' => $dados['ordem_dose'],

                // RF27b — a divergência só é legível contra o que foi sugerido, e
                // a sugestão é calculada aqui, no servidor, no momento da escrita:
                // não é dado que o formulário possa afirmar sobre si mesmo.
                'ordem_dose_sugerida' => $this->ordemSugerida($doses),

                'justificativa_conduta' => $dados['justificativa_conduta'] ?? null,
                'observacao' => $dados['observacao'] ?? null,

                // RF25b — do usuário autenticado, jamais do corpo do pedido.
                'aplicador_nome' => $profissional->name,
                'aplicador_crmv' => $profissional->crmvEm($prestador),
                'aplicador_user_id' => $profissional->id,

                'validade_expirada_confirmada' => $this->venceuAntesDaAplicacao($validade, $aplicadoEm),

                // A autoria do ato já está em `aplicador_*`; esta coluna é de
                // quem transcreve o que não presenciou (RF29b).
                'lancado_por_user_id' => null,
            ]);
        });
    }

    /**
     * O catálogo do formulário. Traz mais que o de T09 porque V07 pré-preenche:
     * fabricante e via usual entram como ponto de partida quando não há
     * aplicação anterior de onde copiá-los (RNF15).
     *
     * @return list<array<string, mixed>>
     */
    private function catalogoPara(Animal $animal, Prestador $prestador): array
    {
        return Imunobiologico::query()
            ->paraEspecieDe($animal, $prestador)
            ->orderBy('nome_comercial')
            ->get()
            ->map(fn (Imunobiologico $imunobiologico) => $this->apresentar($imunobiologico))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function apresentar(Imunobiologico $imunobiologico): array
    {
        return [
            'chave' => $imunobiologico->chave,
            'nome' => $imunobiologico->nome_comercial,
            'nome_tecnico' => $imunobiologico->nome_tecnico,
            'classificacao' => $imunobiologico->classificacao,

            // A lista mistura dois acervos, e a origem muda o peso do que se
            // lê: o item da plataforma vem de diretriz, o próprio vem do
            // agendamento que a clínica declarou em A04. É o veterinário quem
            // responde pelo ato (RN36), e ele precisa saber qual dos dois está
            // escolhendo.
            'propria' => ! $imunobiologico->daPlataforma(),
            'fabricante' => $imunobiologico->fabricante,
            'via_administracao_usual' => $imunobiologico->via_administracao_usual,
        ];
    }

    /**
     * RNF15 — o coração do critério de noventa segundos: os valores repetidos da
     * última aplicação do mesmo imunobiológico **neste prestador**.
     *
     * De outro prestador, não: lote e validade descrevem um frasco que esteve
     * nas mãos de quem aplicou, e sugerir a alguém o número do frasco de outra
     * clínica convidaria a confirmar, sem conferir, um dado que nunca foi seu.
     *
     * @return array<string, mixed>|null
     */
    private function sugestoesDe(Animal $animal, Prestador $prestador, Imunobiologico $imunobiologico): ?array
    {
        /** @var Vacinacao|null $ultima */
        $ultima = Vacinacao::query()
            ->where('prestador_id', $prestador->id)
            ->where('imunobiologico_id', $imunobiologico->id)
            ->where('origem', 'profissional')
            ->whereNotNull('lote')
            ->latest('aplicado_em')
            ->first();

        if ($ultima === null) {
            return null;
        }

        return [
            'fabricante' => $ultima->fabricante,
            'lote' => $ultima->lote,
            'validade' => $ultima->validade?->toDateString(),
            'via_administracao' => $ultima->via_administracao,
            'aplicada_em' => $ultima->aplicado_em?->toDateString(),

            // O animal da aplicação anterior **não** é nomeado: a sugestão vem
            // do estoque da clínica, e dizer de qual paciente o lote saiu
            // contaria a um tutor algo sobre o atendimento de outro.
            'de_outro_animal' => $ultima->animal_id !== $animal->id,
        ];
    }

    /**
     * As doses já aplicadas deste imunobiológico neste animal, na mesma ordem e
     * com as mesmas relações que `montarCarteira()` carrega — a coleção
     * hipotética precisa ser indistinguível da real.
     *
     * @return Collection<int, Vacinacao>
     */
    private function dosesDoGrupo(Animal $animal, Imunobiologico $imunobiologico): Collection
    {
        return $animal->vacinacoes()
            // V09 — a versão substituída não conta como dose: a ordem sugerida
            // e os alertas saem da série, e a série é a das versões que valem.
            ->vigente()
            ->with(['imunobiologico', 'protocoloVacinal', 'prestador', 'lancadoPor'])
            ->where('imunobiologico_id', $imunobiologico->id)
            ->orderBy('aplicado_em')
            ->get();
    }

    /**
     * Que dose da série esta seria, se ninguém mexesse. É a contagem das doses
     * já aplicadas mais um — a mesma conta que `calcularProximaDose()` faz para
     * prever, vista do outro lado.
     *
     * @param  Collection<int, Vacinacao>  $doses
     */
    private function ordemSugerida(Collection $doses): int
    {
        $ultimaDeclarada = $doses
            ->filter(fn (Vacinacao $dose) => $dose->ordem_dose !== null)
            ->sortByDesc('aplicado_em')
            ->first();

        return ($ultimaDeclarada?->ordem_dose ?? $doses->count()) + 1;
    }

    /**
     * As opções do seletor de ordem. Vão até um passo além da série primária —
     * o reforço —, e sempre incluem a sugerida e a escolhida, para que a tela
     * nunca exiba um valor que a própria lista não oferece.
     *
     * @return list<array{valor: int, rotulo: string}>
     */
    private function opcoesDeOrdem(?ProtocoloVacinal $protocolo, int $sugerida, int $escolhida): array
    {
        $serie = $protocolo?->numero_doses_serie_primaria ?? 1;

        // Agendamento sem revacinação não tem reforço a oferecer: a série
        // acaba na última dose dela. Oferecer o passo a mais faria a tela
        // propor uma dose que o calendário nunca vai prever — e o rótulo dessa
        // opção sairia de uma periodicidade que não existe.
        $maximo = max(
            $protocolo?->semRevacinacao() === true ? $serie : $serie + 1,
            $sugerida,
            $escolhida,
        );

        return collect(range(1, $maximo))
            ->map(fn (int $ordem) => [
                'valor' => $ordem,
                'rotulo' => $this->calendario->rotuloDaDose($ordem, $protocolo),
            ])
            ->all();
    }

    /**
     * A vacinação que ainda não existe, montada como se existisse. Não é
     * gravada e não é gravável a partir daqui — serve só para que o cálculo a
     * enxergue no meio das outras.
     *
     * As relações são atribuídas à mão porque o modelo não passou pelo banco e
     * não tem de onde carregá-las; o identificador zero dá ao registro uma
     * chave estável no mapa de rótulos, sem colidir com id algum real.
     */
    private function doseHipotetica(
        Animal $animal,
        Prestador $prestador,
        Imunobiologico $imunobiologico,
        ?ProtocoloVacinal $protocolo,
        Carbon $aplicadoEm,
        int $ordem,
    ): Vacinacao {
        $dose = new Vacinacao([
            'animal_id' => $animal->id,
            'prestador_id' => $prestador->id,
            'imunobiologico_id' => $imunobiologico->id,
            'protocolo_vacinal_id' => $protocolo?->id,
            'origem' => 'profissional',
            'aplicado_em' => $aplicadoEm,
            'data_aproximada' => false,
            'ordem_dose' => $ordem,
        ]);

        $dose->id = 0;
        $dose->setRelation('imunobiologico', $imunobiologico);
        $dose->setRelation('protocoloVacinal', $protocolo);
        $dose->setRelation('prestador', $prestador);

        return $dose;
    }

    /**
     * A carteira inteira do animal com a dose hipotética no lugar cronológico
     * que ela ocupará. É a carteira inteira, e não só o grupo, porque a
     * situação que o painel anuncia — "situação após registrar" — é a do
     * animal, e uma antirrábica em dia não põe em dia a V10 atrasada.
     *
     * @return Collection<int, Vacinacao>
     */
    private function carteiraHipotetica(Animal $animal, Vacinacao $hipotetica): Collection
    {
        return $animal->vacinacoes()
            ->with(['imunobiologico', 'protocoloVacinal', 'prestador', 'lancadoPor'])
            ->orderBy('aplicado_em')
            ->get()
            ->push($hipotetica)
            ->sortBy(fn (Vacinacao $dose) => $dose->aplicado_em?->timestamp ?? 0)
            ->values();
    }

    /**
     * @param  array<string, mixed>|null  $grupo
     * @param  array<string, mixed>  $carteira
     * @return array<string, mixed>|null
     */
    private function calculoDe(?array $grupo, array $carteira, ?ProtocoloVacinal $protocolo): ?array
    {
        if ($grupo === null || $grupo['proxima_dose'] === null) {
            // Duas ausências diferentes, e o painel de V07 tem de distinguir:
            // sem protocolo não há data a prometer (RF50, e o campo vazio é a
            // resposta honesta); sem revacinação há uma resposta, e ela é que
            // não haverá outra dose. Calar aqui deixaria o veterinário lendo um
            // painel vazio no meio do registro, que se lê como falha.
            return $protocolo?->semRevacinacao() === true ? [
                'prevista_para' => null,
                'rotulo' => 'Série concluída',
                'regra_texto' => 'Dose única — este agendamento não prevê revacinação.',
                'protocolo_versao' => $protocolo->versao,
                'situacao_apos' => $this->calendario->situacaoDaCarteira($carteira),
            ] : null;
        }

        return [
            'prevista_para' => $grupo['proxima_dose']['prevista_para'],
            'rotulo' => $grupo['proxima_dose']['rotulo'],
            'regra_texto' => $grupo['proxima_dose']['regra_texto'],
            'protocolo_versao' => $protocolo?->versao,
            'situacao_apos' => $this->calendario->situacaoDaCarteira($carteira),
        ];
    }

    /**
     * Os alertas do painel, todos permissivos (RF27c, RN36): informam, sugerem
     * conduta e pedem justificativa — nenhum deles desabilita coisa alguma.
     *
     * Dois dos quatro estados que o desenho de V07 prevê **não** estão aqui, e a
     * ausência é decisão registrada em `imunia-decisoes.md` §9.8: a validade
     * expirada é verificada na tela e na FormRequest, porque depende de um campo
     * que o profissional ainda está digitando; e o alerta de reação adversa
     * anterior (RF30b) não tem tabela a consultar — RF30 é fatia futura, e um
     * alerta clínico que o sistema não pode sustentar é dano, não lacuna.
     *
     * @param  array<string, mixed>|null  $grupo
     * @param  Collection<int, Vacinacao>  $doses
     * @return list<array<string, mixed>>
     */
    private function alertas(
        ?array $grupo,
        Collection $doses,
        ?ProtocoloVacinal $protocolo,
        Carbon $aplicadoEm,
        int $ordem,
        int $ordemSugerida,
    ): array {
        return array_values(array_filter([
            $this->alertaDeAtraso($doses, $protocolo, $aplicadoEm),
            $this->alertaDeDoseAdicional($grupo),
            $this->alertaDeOrdemAlterada($ordem, $ordemSugerida, $protocolo),
        ]));
    }

    /**
     * RF27a — atraso superior ao limite parametrizado gera alerta explícito, com
     * a conduta que o protocolo sugere. O atraso se mede contra a data que
     * estava prevista **antes** desta aplicação, e por isso o cálculo aqui é o
     * da carteira real, sem a dose hipotética.
     *
     * @param  Collection<int, Vacinacao>  $doses
     * @return array<string, mixed>|null
     */
    private function alertaDeAtraso(Collection $doses, ?ProtocoloVacinal $protocolo, Carbon $aplicadoEm): ?array
    {
        if ($protocolo === null || $doses->isEmpty()) {
            return null;
        }

        $ultimaExata = $doses
            ->filter(fn (Vacinacao $dose) => ! $dose->data_aproximada && $dose->aplicado_em !== null)
            ->sortByDesc('aplicado_em')
            ->first();

        if ($ultimaExata === null) {
            return null;
        }

        $previsao = $this->calendario->preverDoseSeguinte(
            $protocolo,
            $ultimaExata->aplicado_em,
            $ultimaExata->ordem_dose ?? $doses->count(),
            null,
        );

        // Sem dose seguinte prevista não há atraso a medir: a série tinha
        // acabado, e aplicar de novo é decisão clínica, não descumprimento de
        // prazo (RN36).
        if ($previsao['data'] === null) {
            return null;
        }

        $prevista = $previsao['data']->copy()->startOfDay();
        $atraso = (int) $prevista->diffInDays($aplicadoEm->copy()->startOfDay(), false);

        if ($atraso <= $protocolo->limite_atraso_dias) {
            return null;
        }

        return [
            'tipo' => 'atraso',
            'titulo' => 'Atraso acima do limite',
            'texto' => sprintf(
                '%s estava prevista para %s, há %d %s. O limite parametrizado para %s é de %d dias.',
                $previsao['rotulo'],
                $prevista->format('d/m/Y'),
                $atraso,
                $atraso === 1 ? 'dia' : 'dias',
                $protocolo->imunobiologico->nome_comercial,
                $protocolo->limite_atraso_dias,
            ),
            'conduta_sugerida' => $protocolo->descricaoDaConduta(),
            'pede_justificativa' => true,
        ];
    }

    /**
     * RN33 — a dose final da série primária de filhotes não pode ser aplicada
     * antes de dezesseis semanas, porque os anticorpos de origem materna ainda
     * circulantes podem neutralizar o antígeno. O painel avisa que uma dose
     * adicional será agendada, e **não** oferece dispensá-la: a dose adicional é
     * derivada em tempo de leitura, não gravada, e uma justificativa em texto
     * não impediria a carteira de continuar prevendo-a nem os lembretes de
     * continuarem cobrando-a do tutor (§9.8).
     *
     * @param  array<string, mixed>|null  $grupo
     * @return array<string, mixed>|null
     */
    private function alertaDeDoseAdicional(?array $grupo): ?array
    {
        if ($grupo === null || ($grupo['proxima_dose']['tipo'] ?? null) !== 'dose_adicional') {
            return null;
        }

        return [
            'tipo' => 'dose_adicional',
            'titulo' => 'Dose adicional será agendada',
            'texto' => 'A dose final desta série cai antes da idade mínima. Anticorpos de origem materna '
                .'ainda circulantes podem neutralizar o antígeno, por isso o protocolo prevê uma dose '
                .'adicional depois dessa idade.',
            'agendada_para' => $grupo['proxima_dose']['prevista_para'],
            'pede_justificativa' => false,
        ];
    }

    /**
     * RF27b — a ordem alterada é conduta divergente, e conduta divergente se
     * registra com justificativa. O alerta existe para pedi-la; não para
     * impedir a alteração.
     *
     * @return array<string, mixed>|null
     */
    private function alertaDeOrdemAlterada(int $ordem, int $ordemSugerida, ?ProtocoloVacinal $protocolo): ?array
    {
        if ($ordem === $ordemSugerida) {
            return null;
        }

        return [
            'tipo' => 'ordem_alterada',
            'titulo' => 'Ordem da dose alterada',
            'texto' => sprintf(
                'O sistema calculou %s a partir do histórico deste animal, e você registrou %s. '
                .'A alteração fica no registro, com o seu nome.',
                $this->calendario->rotuloDaDose($ordemSugerida, $protocolo),
                $this->calendario->rotuloDaDose($ordem, $protocolo),
            ),
            'pede_justificativa' => true,
        ];
    }

    /**
     * RF25c — a comparação é de dia contra dia. `validade` é uma data pura e
     * `aplicado_em` carrega a hora: sem zerá-la, a vacina aplicada às 14h do seu
     * último dia de validade sairia marcada como vencida, e essa marca é
     * permanente e visível ao tutor na carteira.
     */
    private function venceuAntesDaAplicacao(Carbon $validade, Carbon $aplicadoEm): bool
    {
        return $validade->copy()->startOfDay()->lt($aplicadoEm->copy()->startOfDay());
    }

    /**
     * A repetição do mesmo pedido, e não uma segunda dose: mesmo animal, mesma
     * vacina, mesmo prestador e exatamente o mesmo momento de aplicação, gravado
     * há instantes. Devolve o registro que já existe, em vez de recusar — para
     * quem reenviou sem saber, o desfecho correto é o registro estar lá.
     */
    private function repeticaoRecente(
        Animal $animal,
        Prestador $prestador,
        Imunobiologico $imunobiologico,
        Carbon $aplicadoEm,
    ): ?Vacinacao {
        return $animal->vacinacoes()
            ->where('prestador_id', $prestador->id)
            ->where('imunobiologico_id', $imunobiologico->id)
            ->where('origem', 'profissional')
            ->where('aplicado_em', $aplicadoEm)
            ->where('created_at', '>=', Carbon::now()->subMinutes(self::JANELA_DUPLICIDADE_MINUTOS))
            ->first();
    }

    /**
     * RN49 — a mesma regra de V06, e de propósito a mesma: o histórico pregresso
     * não conta, porque não vem de prestador algum (RN24), e registrá-lo como
     * acesso a dado de terceiro diria ao tutor, em T14, que uma clínica leu o
     * que ele mesmo escreveu sobre o próprio animal.
     */
    private function temRegistroDeOutroPrestador(Animal $animal, Prestador $prestador): bool
    {
        $vacinacaoAlheia = $animal->vacinacoes()
            ->whereNotNull('prestador_id')
            ->where('prestador_id', '!=', $prestador->id)
            ->exists();

        return $vacinacaoAlheia || Atendimento::query()
            ->where('animal_id', $animal->id)
            ->where('prestador_id', '!=', $prestador->id)
            ->exists();
    }

    private function registrarAcesso(User $profissional, Prestador $prestador, Animal $animal): void
    {
        RegistroDeAcesso::create([
            'prestador_id' => $prestador->id,
            'user_id' => $profissional->id,
            'tutor_id' => $animal->tutor_id,
            'animal_id' => $animal->id,
            'natureza' => RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
            'ocorrido_em' => Carbon::now(),
        ]);
    }
}
