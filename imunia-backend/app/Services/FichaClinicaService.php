<?php

namespace App\Services;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Autorizacao;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\User;
use App\Models\Vacinacao;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * V06 — ficha clínica do animal (RF19, RF35). O centro de trabalho do
 * veterinário sobre um animal: identidade, carteira, histórico e anexos em uma
 * resposta só, porque o briefing exige abas que trocam sem recarregar.
 *
 * Duas decisões governam esta classe, e ambas são de conformidade, não de
 * conveniência:
 *
 * 1. **A ausência de autorização é um estado de tela, não um erro** (P2, RF35c).
 *    Sem autorização vigente a rota responde 200 com espécie, nome e código — e
 *    nada mais. Nem o nome do tutor, nem a contagem de registros, nem a data do
 *    último atendimento, que já seriam informação clínica sobre um animal que
 *    ninguém autorizou este prestador a acompanhar.
 *
 * 2. **A gravação do log é condição da exibição** (RF52b). O registro de acesso
 *    acontece antes de a resposta ser montada, e não depois de enviada: se a
 *    linha não puder ser gravada, o histórico do outro prestador não é exibido.
 *    É a mesma ordem que `BuscaClinicaService` observa em V03, pelo mesmo
 *    motivo.
 *
 * O que esta classe não faz: calcular calendário e montar linha do tempo. Isso é
 * de `CalendarioVacinalService` e de `HistoricoConsolidadoService`, e reusá-los
 * é o que garante que a ficha do veterinário e a carteira do tutor nunca digam
 * números diferentes sobre a mesma dose.
 */
class FichaClinicaService
{
    public function __construct(
        private readonly CalendarioVacinalService $calendario,
        private readonly HistoricoConsolidadoService $historico,
        private readonly SolicitacoesDeAcessoService $solicitacoes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function montar(User $profissional, Prestador $prestador, Animal $animal): array
    {
        $autorizacao = $this->autorizacaoVigente($animal, $prestador);

        if ($autorizacao === null) {
            return $this->fichaSemAutorizacao($profissional, $prestador, $animal);
        }

        return $this->fichaAutorizada($profissional, $prestador, $animal, $autorizacao);
    }

    private function autorizacaoVigente(Animal $animal, Prestador $prestador): ?Autorizacao
    {
        return $animal->autorizacoes()
            ->where('prestador_id', $prestador->id)
            ->vigente()
            ->latest('expira_em')
            ->first();
    }

    /**
     * O estado P2. Identificação mínima, a explicação do porquê e um caminho
     * único — pedir a autorização a quem pode dá-la.
     *
     * O acesso fica registrado (RF52, RN49) e a tela o anuncia: o profissional
     * abriu a ficha de um animal fora do seu âmbito, e o tutor verá que abriu.
     * Registrar aqui, e não só na busca de V03, é o que impede que a ficha seja
     * a porta de trás do log — chegar por endereço direto não pode custar menos
     * do que chegar pela busca.
     *
     * @return array<string, mixed>
     */
    private function fichaSemAutorizacao(User $profissional, Prestador $prestador, Animal $animal): array
    {
        $this->registrarAcesso($profissional, $prestador, $animal, RegistroDeAcesso::FICHA_SEM_AUTORIZACAO);

        return [
            'acesso' => 'sem_autorizacao',

            // RF35c e RF18a — espécie, nome e código. A ausência do tutor não é
            // omissão de tela: é o conteúdo do estado.
            'animal' => [
                'codigo' => $animal->codigo,
                'nome' => $animal->nome,
                'especie' => $animal->especie,
            ],
            'autorizacao' => null,

            // V10 — o pedido já feito e ainda sem resposta, para a tela trocar
            // o botão de solicitar pela etiqueta de espera. É ato do próprio
            // prestador, não dado do tutor.
            'solicitacao_pendente' => $this->solicitacoes->pendenciaParaAnimal($prestador, $animal),

            'acesso_registrado' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fichaAutorizada(
        User $profissional,
        Prestador $prestador,
        Animal $animal,
        Autorizacao $autorizacao,
    ): array {
        $animal->loadMissing(['tutor.user', 'obitoRegistradoPor']);

        $carteira = $this->calendario->montarCarteira($animal);
        $atendimentos = $this->atendimentosDe($animal);
        $deOutroPrestador = $this->temRegistroDeOutroPrestador($animal, $atendimentos, $prestador);

        // RF52b — antes de montar o que será exibido, e não depois. A ordem das
        // duas linhas abaixo é a regra.
        if ($deOutroPrestador) {
            $this->registrarAcesso(
                $profissional,
                $prestador,
                $animal,
                RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
            );
        }

        return [
            'acesso' => 'autorizado',
            'animal' => $this->identificar($animal),
            'autorizacao' => $this->apresentarAutorizacao($autorizacao),

            // O aviso em `--consent-wash` que a tela exibe **antes** do
            // conteúdo. Viaja como dado porque a frase depende de haver
            // registro alheio, e a tela não tem como saber isso sozinha sem
            // percorrer o histórico inteiro por conta própria.
            'aviso_outro_prestador' => $deOutroPrestador,

            'alertas' => $this->alertas($animal, $autorizacao, $carteira, $atendimentos),
            'resumo' => $this->resumo($prestador, $carteira, $atendimentos),
            'carteira' => $carteira,
            'historico' => $this->historico->montar($animal),
            'anexos' => $this->anexos($animal, $atendimentos),
        ];
    }

    /**
     * O cabeçalho de 96 px do briefing, e o que a aba Resumo detalha.
     *
     * @return array<string, mixed>
     */
    private function identificar(Animal $animal): array
    {
        return [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,
            'sexo' => $animal->sexo,
            'microchip' => $animal->microchip,
            'idade_em_meses' => $animal->idadeEmMeses(),
            'nascimento_em' => $animal->nascimento_em?->toDateString(),

            // RN14 — a natureza da data acompanha a data por toda parte. "18
            // meses" a partir de estimativa do tutor não é a mesma afirmação
            // que "18 meses" confirmados por veterinário, e a ficha clínica é
            // justamente onde a diferença decide conduta (RN33).
            'nascimento_exato' => $animal->nascimento_exato,
            'foto_url' => $animal->fotoUrl(),
            'preliminar' => $animal->preliminar(), // RN17
            'tutor' => [
                'nome' => $animal->tutor->nome,
                'email' => $animal->tutor->user?->email,

                // RF14b — tutor cadastrado pelo prestador que ainda não ativou
                // o acesso é sinalizado nas telas do prestador. Enquanto não
                // ativa, não recebe lembrete nem concede autorização, e o
                // profissional precisa saber disso antes de contar com ele.
                'ativado' => $animal->tutor->user?->ativado_em !== null,
            ],

            // RF22 — o óbito não é erro nem alerta: é fato, em tom neutro
            // (§4.1 do briefing, "nenhum vermelho para óbito").
            'obito' => $animal->inativo() ? [
                'em' => $animal->obito_em->toDateString(),
                'causa' => $animal->obito_causa,
                'registrado_por' => $animal->obitoRegistradoPor?->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function apresentarAutorizacao(Autorizacao $autorizacao): array
    {
        return [
            'concedida_em' => $autorizacao->concedida_em->toDateString(),
            'expira_em' => $autorizacao->expira_em->toDateString(),
            'dias_restantes' => $autorizacao->diasRestantes(),

            // A mesma antecedência de T12: o alerta que a clínica vê e o âmbar
            // que o tutor vê acendem no mesmo dia, ou um dos dois estaria
            // pedindo renovação que o outro ainda não julga necessária.
            'a_expirar' => $autorizacao->situacao() === 'a_expirar',
        ];
    }

    /**
     * O painel lateral do briefing: alerta clínico **acima** de alerta
     * administrativo, sempre. A ordem não é estética — é a diferença entre uma
     * dose atrasada e um prazo de papelada disputando o mesmo canto da tela.
     *
     * Falta aqui o alerta de reação adversa que o desenho prevê: RF30 é fatia
     * própria (V11) e não há tabela a consultar. Fabricá-lo a partir de outro
     * dado seria pior do que a ausência — nesta tela, um alerta clínico que o
     * sistema não pode sustentar é dano, não lacuna.
     *
     * @param  array<string, mixed>  $carteira
     * @param  Collection<int, Atendimento>  $atendimentos
     * @return array{clinicos: list<array<string, mixed>>, administrativos: list<array<string, mixed>>}
     */
    private function alertas(
        Animal $animal,
        Autorizacao $autorizacao,
        array $carteira,
        Collection $atendimentos,
    ): array {
        return [
            'clinicos' => array_values(array_filter([
                ...$this->alertasDeDoseAtrasada($animal, $carteira),
                $this->alertaDeValidadeExpirada($animal),
            ])),
            'administrativos' => array_values(array_filter([
                $this->alertaDeObito($animal),
                $this->alertaDeCadastroPreliminar($animal),
                $this->alertaDeAutorizacao($animal, $autorizacao),
                $this->alertaDeRetorno($animal, $atendimentos),
                $this->alertaDeTutorNaoAtivado($animal),
            ])),
        ];
    }

    /**
     * Uma por imunobiológico atrasado, e não uma linha só somando todos: o
     * profissional precisa saber *qual* vacina venceu para decidir o que
     * aplicar, e "3 doses atrasadas" não responde a isso.
     *
     * @param  array<string, mixed>  $carteira
     * @return list<array<string, mixed>>
     */
    private function alertasDeDoseAtrasada(Animal $animal, array $carteira): array
    {
        // RF22a — sem calendário para um animal com óbito registrado, não há
        // dose atrasada a cobrar de ninguém.
        if ($animal->inativo()) {
            return [];
        }

        return collect($carteira['proximas_doses'])
            ->filter(fn (array $dose) => $dose['situacao']['tipo'] === 'atrasada')
            ->map(fn (array $dose) => [
                'chave' => "dose-atrasada-{$dose['imunobiologico_chave']}",
                'tom' => 'atraso',
                'icone' => 'clock-alert',
                'titulo' => 'Dose atrasada',
                // "em", e não "prevista para": o rótulo da dose ora é feminino
                // ("3ª dose"), ora masculino ("Reforço anual"), e o particípio
                // concordaria com um deles e erraria no outro.
                'texto' => sprintf(
                    '%s · %s em %s, %s.',
                    Str::ucfirst($dose['imunobiologico']),
                    $dose['rotulo'],
                    Carbon::parse($dose['prevista_para'])->format('d/m/Y'),
                    $dose['situacao']['texto_curto'],
                ),

                // P4 — o sistema calcula e sugere; quem decide é o profissional.
                // A nota diz de onde veio o número para que ele possa discordar
                // dele com conhecimento de causa, e não contra uma caixa-preta.
                'nota' => $dose['regra_texto'],
                'acao' => [
                    'rotulo' => 'Registrar agora',
                    'destino' => "/clinica/animais/{$animal->codigo}/vacinar",
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * RF25c — a aplicação com validade expirada é registrada, confirmada pelo
     * profissional e **sinalizada**. Na carteira ela aparece como tarja no selo
     * de lote; aqui vira alerta porque a ficha é onde se decide a próxima
     * conduta, e uma falha vacinal provável muda essa decisão.
     *
     * @return array<string, mixed>|null
     */
    private function alertaDeValidadeExpirada(Animal $animal): ?array
    {
        $aplicacoes = $animal->vacinacoes()
            // V09 — a marca é da versão que vale. Uma validade lançada errada e
            // corrigida por retificação (RF33) deixaria aqui um alerta clínico
            // que o registro vigente já não sustenta.
            ->vigente()
            ->where('validade_expirada_confirmada', true)
            ->with('imunobiologico')
            ->orderByDesc('aplicado_em')
            ->get();

        if ($aplicacoes->isEmpty()) {
            return null;
        }

        /** @var Vacinacao $ultima */
        $ultima = $aplicacoes->first();

        return [
            'chave' => 'validade-expirada',
            'tom' => 'atraso',
            'icone' => 'triangle-alert',
            'titulo' => 'Aplicação com validade expirada',
            'texto' => sprintf(
                '%s aplicada em %s com o imunobiológico fora da validade, confirmada pelo profissional. %s',
                $ultima->imunobiologico?->nome_comercial ?? 'Vacina não identificada',
                $ultima->aplicado_em?->format('d/m/Y') ?? 'data não informada',
                $aplicacoes->count() > 1
                    ? "Há {$aplicacoes->count()} registros nesta condição na carteira."
                    : 'Avalie a necessidade de repetir a dose.',
            ),
            'nota' => null,
            'acao' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function alertaDeObito(Animal $animal): ?array
    {
        if (! $animal->inativo()) {
            return null;
        }

        return [
            'chave' => 'obito',
            // §4.1 — óbito é neutro. Nem vermelho de erro, nem âmbar de atenção:
            // não é nenhuma das duas coisas.
            'tom' => 'neutro',
            'icone' => 'moon',
            'titulo' => 'Óbito',
            'texto' => sprintf(
                'Registrado em %s%s.',
                $animal->obito_em->format('d/m/Y'),
                $animal->obitoRegistradoPor === null ? '' : " por {$animal->obitoRegistradoPor->name}",
            ),

            // A causa só quando informada (V12): a ausência não vira "causa não
            // informada", porque aqui o silêncio não é pendência de ninguém.
            'nota' => $animal->obito_causa,
            'acao' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function alertaDeCadastroPreliminar(Animal $animal): ?array
    {
        if (! $animal->preliminar()) {
            return null;
        }

        return [
            'chave' => 'cadastro-preliminar',
            'tom' => 'consentimento',
            'icone' => 'user-round',
            'titulo' => 'Cadastro preliminar',
            'texto' => 'Cadastro feito pelo tutor, sem caracterização por veterinário. '
                .'Raça, pelagem, sexo e peso não foram informados.',

            // RN18 — a razão de o campo estar vazio é a razão de ele ser
            // privativo, e dizê-la evita que a ausência seja lida como descuido
            // do tutor.
            'nota' => 'Os campos de caracterização são privativos do médico-veterinário: '
                .'o tutor não pode preenchê-los.',
            'acao' => [
                'rotulo' => 'Completar caracterização',
                'destino' => "/clinica/animais/{$animal->codigo}/caracterizar",
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function alertaDeAutorizacao(Animal $animal, Autorizacao $autorizacao): ?array
    {
        $dias = $autorizacao->diasRestantes();

        if ($autorizacao->situacao() !== 'a_expirar') {
            return null;
        }

        return [
            'chave' => 'autorizacao-a-expirar',
            'tom' => 'atencao',
            'icone' => 'key-round',
            'titulo' => 'Autorização a expirar',
            'texto' => sprintf(
                'Expira em %s, %s.',
                $autorizacao->expira_em->format('d/m/Y'),
                match (true) {
                    $dias <= 0 => 'hoje',
                    $dias === 1 => 'amanhã',
                    default => "em {$dias} dias",
                },
            ),

            // RN37 e RF36 — o prestador pede; só o titular concede. Dizer isso
            // na própria tarja evita a expectativa de um botão que renove.
            'nota' => 'A renovação é ato do tutor: o prestador pode pedi-la, nunca concedê-la.',
            'acao' => [
                'rotulo' => 'Pedir renovação',
                'destino' => "/clinica/autorizacoes/nova?animal={$animal->codigo}",
            ],
        ];
    }

    /**
     * RF34 — retorno programado em aberto. RF34c encerra-o automaticamente
     * quando há atendimento novo depois da data prevista, e é essa a conta
     * feita aqui: o retorno cumprido some da ficha sem que ninguém precise
     * marcá-lo como cumprido.
     *
     * @param  Collection<int, Atendimento>  $atendimentos
     * @return array<string, mixed>|null
     */
    private function alertaDeRetorno(Animal $animal, Collection $atendimentos): ?array
    {
        if ($animal->inativo()) {
            return null;
        }

        $ultimoAtendimento = $atendimentos->max('atendido_em');

        $retorno = $atendimentos
            ->filter(fn (Atendimento $atendimento) => $atendimento->retorno_em !== null)
            ->reject(fn (Atendimento $atendimento) => $ultimoAtendimento?->gt($atendimento->retorno_em) ?? false)
            ->sortBy('retorno_em')
            ->first();

        if ($retorno === null) {
            return null;
        }

        $dias = (int) Carbon::today()->diffInDays($retorno->retorno_em, absolute: false);

        return [
            'chave' => 'retorno-em-aberto',
            'tom' => $dias < 0 ? 'atencao' : 'neutro',
            'icone' => 'calendar-clock',
            'titulo' => 'Retorno em aberto',
            'texto' => sprintf(
                '%s · %s%s',
                $retorno->retorno_em->format('d/m/Y'),
                $retorno->retorno_finalidade ?? 'sem finalidade descrita',
                $dias < 0 ? sprintf(' (previsto há %d dias)', abs($dias)) : '',
            ),
            'nota' => null,
            'acao' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function alertaDeTutorNaoAtivado(Animal $animal): ?array
    {
        if ($animal->tutor->user?->ativado_em !== null) {
            return null;
        }

        return [
            'chave' => 'tutor-nao-ativado',
            'tom' => 'atencao',
            'icone' => 'user-round',
            'titulo' => 'Tutor ainda não ativou o acesso',
            // RF14 — enquanto não ativa, o tutor não recebe lembrete e não
            // concede autorização. As duas consequências importam à conduta da
            // clínica, e por isso são ditas, e não deixadas subentendidas.
            'texto' => sprintf(
                '%s recebeu o convite e ainda não definiu a senha. Até lá não recebe lembretes '
                .'de dose nem consegue autorizar prestadores.',
                $animal->tutor->nome,
            ),
            'nota' => null,
            'acao' => null,
        ];
    }

    /**
     * A aba Resumo: o que o profissional precisa saber antes de decidir o que
     * registrar. Não repete a carteira nem a linha do tempo — aponta para elas.
     *
     * @param  array<string, mixed>  $carteira
     * @param  Collection<int, Atendimento>  $atendimentos
     * @return array<string, mixed>
     */
    private function resumo(Prestador $prestador, array $carteira, Collection $atendimentos): array
    {
        // V09 — a versão que vale, e a contagem das consultas que houve. Uma
        // retificação não é um segundo atendimento: contá-la faria a ficha
        // dizer "2 registros" onde houve uma consulta, e o cartão de "último
        // atendimento" poderia exibir a versão corrigida ou a corrigida
        // conforme o desempate, já que as duas têm a mesma data.
        $vigentes = $atendimentos->filter(
            fn (Atendimento $atendimento) => $atendimento->retificacao === null
        );

        /** @var Atendimento|null $ultimo */
        $ultimo = $vigentes->sortByDesc('atendido_em')->first();

        return [
            'situacao' => $this->calendario->situacaoDaCarteira($carteira),
            'contagens' => $carteira['resumo'],

            // As três primeiras, e não todas: o painel lateral já cobra as
            // atrasadas, e a lista inteira é a aba Carteira.
            'proximas_doses' => array_slice($carteira['proximas_doses'], 0, 3),
            'ultimo_atendimento' => $ultimo === null ? null : [
                'id' => $ultimo->id,
                'titulo' => $ultimo->titulo,
                'data' => $ultimo->atendido_em->toDateString(),
                'motivo' => $ultimo->motivo,

                // P1 — a procedência é conteúdo de primeira classe, também aqui:
                // o resumo diz quem atendeu, e não só que houve atendimento.
                'prestador' => $ultimo->prestador->nome,
                'profissional' => $ultimo->profissional_nome,
                'crmv' => $ultimo->profissional_crmv,

                // Qual das duas variantes de chip a tela desenha: a comum ou a
                // que leva o ícone de olho, por ser registro de outro prestador
                // e a leitura estar sendo gravada (§5.2, `other-provider`).
                'de_outro_prestador' => $ultimo->prestador_id !== $prestador->id,
            ],
            'total_de_atendimentos' => $vigentes->count(),
        ];
    }

    /**
     * A aba Anexos: os exames e documentos de todos os atendimentos, do mais
     * recente para o mais antigo (RF32).
     *
     * O endereço de cada arquivo é o da rota clínica, que confere a autorização
     * do prestador ativo a cada pedido (RF32c). Não é a rota de T08: aquela
     * pergunta pela titularidade do tutor, e o veterinário não é titular de
     * animal nenhum.
     *
     * @param  Collection<int, Atendimento>  $atendimentos
     * @return list<array<string, mixed>>
     */
    private function anexos(Animal $animal, Collection $atendimentos): array
    {
        return $atendimentos
            ->sortByDesc('atendido_em')
            ->flatMap(fn (Atendimento $atendimento) => $atendimento->anexos->map(
                fn (AnexoAtendimento $anexo) => [
                    'id' => $anexo->id,
                    'descricao' => $anexo->descricao,
                    'tipo' => $anexo->tipo,
                    'exame_em' => $anexo->exame_em?->toDateString(),
                    'disponivel' => $anexo->disponivel(),
                    'url' => "/api/clinica/animais/{$animal->codigo}/anexos/{$anexo->id}",

                    // De qual atendimento veio, e de quem. Um anexo solto na
                    // aba não diz nada; o laudo importa junto com a consulta
                    // que o pediu.
                    'atendimento' => [
                        'id' => $atendimento->id,
                        'titulo' => $atendimento->titulo,
                        'data' => $atendimento->atendido_em->toDateString(),
                        'prestador' => $atendimento->prestador->nome,
                    ],
                ],
            ))
            ->values()
            ->all();
    }

    /**
     * O anexo pedido pela aba Anexos (RF32c). A conferência acontece a cada
     * pedido, e não uma vez na montagem da ficha: uma autorização revogada
     * entre abrir a tela e clicar no arquivo tem que fechar o arquivo.
     *
     * A leitura de anexo produzido por outro prestador é acesso a registro
     * alheio como qualquer outro, e gera a sua linha de log (RN49). Abrir o
     * laudo é justamente o ato que P6 descreve — o segundo profissional lendo o
     * exame que o primeiro pediu —, e é o que o tutor tem direito de ver em
     * T14.
     */
    public function localizarAnexo(
        User $profissional,
        Prestador $prestador,
        Animal $animal,
        int $anexo,
    ): AnexoAtendimento {
        abort_if(
            $this->autorizacaoVigente($animal, $prestador) === null,
            403,
            'Este animal não está sob autorização vigente para o prestador ativo.',
        );

        /** @var AnexoAtendimento|null $arquivo */
        $arquivo = AnexoAtendimento::query()
            ->with('atendimento')
            ->whereHas('atendimento', fn ($atendimento) => $atendimento->where('animal_id', $animal->id))
            ->find($anexo);

        abort_if($arquivo === null, 404, 'Anexo não encontrado.');
        abort_if(! $arquivo->disponivel(), 404, 'Este anexo não está disponível agora.');

        if ($arquivo->atendimento->prestador_id !== $prestador->id) {
            $this->registrarAcesso(
                $profissional,
                $prestador,
                $animal,
                RegistroDeAcesso::HISTORICO_DE_OUTRO_PRESTADOR,
            );
        }

        return $arquivo;
    }

    /**
     * @return Collection<int, Atendimento>
     */
    private function atendimentosDe(Animal $animal): Collection
    {
        return $animal->atendimentos()
            // `retificacao` vem junto porque o resumo precisa saber qual versão
            // de cada prontuário vale hoje (V09). A aba Anexos e a linha do
            // tempo continuam recebendo todas: o anexo pertence ao registro
            // original (RF32), e a linha do tempo mostra as duas versões (RF33).
            ->with(['prestador', 'anexos', 'retificacao'])
            ->orderByDesc('atendido_em')
            ->get();
    }

    /**
     * RN49 — "registro clínico originado de outro prestador". O histórico
     * pregresso não conta: ele não vem de prestador algum, foi lançado pelo
     * próprio tutor e não carrega responsabilidade técnica de ninguém (RN24).
     * Registrá-lo como acesso a dado de terceiro diria ao tutor, em T14, que
     * uma clínica leu o que ele mesmo escreveu sobre o próprio animal.
     *
     * @param  Collection<int, Atendimento>  $atendimentos
     */
    private function temRegistroDeOutroPrestador(
        Animal $animal,
        Collection $atendimentos,
        Prestador $prestador,
    ): bool {
        $vacinacaoAlheia = $animal->vacinacoes()
            ->whereNotNull('prestador_id')
            ->where('prestador_id', '!=', $prestador->id)
            ->exists();

        return $vacinacaoAlheia || $atendimentos->contains(
            fn (Atendimento $atendimento) => $atendimento->prestador_id !== $prestador->id,
        );
    }

    /**
     * Uma linha do livro de acessos (RF52). O tutor sempre viaja junto do
     * animal: T14 responde por titular, e deduzir a titularidade na leitura
     * quebraria quando o animal fosse transferido (RF21).
     */
    private function registrarAcesso(
        User $profissional,
        Prestador $prestador,
        Animal $animal,
        string $natureza,
    ): void {
        RegistroDeAcesso::create([
            'prestador_id' => $prestador->id,
            'user_id' => $profissional->id,
            'tutor_id' => $animal->tutor_id,
            'animal_id' => $animal->id,
            'natureza' => $natureza,
            'ocorrido_em' => now(),
        ]);
    }
}
