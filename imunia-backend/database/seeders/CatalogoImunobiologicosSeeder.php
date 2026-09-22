<?php

namespace Database\Seeders;

use App\Models\Imunobiologico;
use App\Models\ProtocoloVacinal;
use App\Models\User;
use App\Models\VersaoProtocolo;
use Illuminate\Database\Seeder;

/**
 * Catálogo de imunobiológicos (RF23) e as versões do cálculo de calendário
 * (RF24), mantidos pela administração da plataforma em X01 e X02.
 *
 * Os parâmetros vêm das *2024 guidelines for the vaccination of dogs and cats*
 * do Vaccination Guidelines Group da WSAVA — a fonte que RN31 exige rastreável
 * e que o campo `base` de cada versão nomeia. O nome comercial é o brasileiro,
 * porque é o que o veterinário escolhe no balcão em V07; a denominação técnica
 * e os agentes trazem a leitura da diretriz.
 *
 * Três decisões desta semeadura divergem da leitura literal da tabela da WSAVA,
 * e cada uma está comentada onde acontece: a periodicidade dos polivalentes
 * caninos, a da antirrábica e a classificação da leucemia felina. As duas
 * primeiras são a "nota de verificação pendente" da §6.4 de `requisitos.md`
 * respondida com a prática brasileira, que é o que a própria diretriz manda
 * fazer quando há regulamentação local.
 *
 * Não são semeadas as vacinas que a WSAVA classifica como **não recomendadas**
 * — coronavirose canina, peritonite infecciosa felina e Microsporum canis —
 * porque `classificacao` só distingue essencial de não essencial, e cadastrar
 * uma delas como "não essencial" diria que a diretriz a admite. A giárdia é a
 * exceção deliberada, e entra inativa: ver o comentário dela.
 *
 * As três versões existem para que X02 tenha o que mostrar em cada situação —
 * uma encerrada, uma vigente e um rascunho em edição —, e para que a pergunta
 * previsível da banca tenha resposta demonstrável: publica-se a 2026.1 e as
 * datas calculadas sob a 2024.1 continuam onde estavam (RN32).
 */
class CatalogoImunobiologicosSeeder extends Seeder
{
    /**
     * Série essencial canina — V8 e V10.
     *
     * WSAVA: não iniciar antes das 6 semanas e revacinar a cada 3 a 4 semanas
     * até as 16 semanas de idade (RN33, RN34). A janela fica em 2 a 4 semanas,
     * que é o que a tabela admite para o intervalo entre doses.
     *
     * A periodicidade é **anual, e não trienal**. A diretriz recomenda revacinar
     * o núcleo viral (cinomose, adenovirose, parvovirose) a cada três anos, mas
     * tanto a V8 quanto a V10 contêm leptospirose, cuja revacinação é anual pela
     * mesma tabela — e é o componente de prazo mais curto que rege o produto.
     * Um polivalente aplicado a cada três anos deixaria o cão sem cobertura de
     * leptospirose por dois deles.
     *
     * Duas doses no adulto sem histórico pelo mesmo motivo: uma dose de vacina
     * viva modificada bastaria para o núcleo viral, mas a bacterina de
     * Leptospira precisa de duas para imunizar.
     */
    private const ESSENCIAL_CANINA = [
        'numero_doses_serie_primaria' => 3,
        'intervalo_minimo_dias' => 14,
        'intervalo_maximo_dias' => 28,
        'idade_minima_primeira_dose_semanas' => 6,
        'idade_minima_dose_final_semanas' => 16,
        'reforco_inicial_meses' => null,
        'periodicidade_revacinacao_meses' => 12,
        'doses_adulto_sem_historico' => 2,
    ];

    /**
     * Série essencial felina — tríplice.
     *
     * WSAVA: não iniciar antes das 6 semanas, revacinar a cada 3 a 4 semanas até
     * as 16 semanas, considerar o reforço por volta dos 6 meses de idade em vez
     * de esperar os 12 a 16 meses, e depois revacinar o gato de baixo risco não
     * mais frequentemente que a cada três anos (RN35).
     *
     * O reforço inicial de 6 meses é uma aproximação declarada: a diretriz o
     * ancora na **idade** do animal, e o motor o conta a partir da **última
     * aplicação**. Contado de uma dose final às 16 semanas, cai por volta dos 10
     * meses de idade — entre os 6 meses que a WSAVA prefere e os 12 a 16 que ela
     * quer evitar. Ancorar por idade exigiria um parâmetro que
     * `protocolos_vacinais` não tem, e inventá-lo aqui seria mudar o motor para
     * caber num caso.
     *
     * É este par — reforço inicial de 6 meses e periodicidade de 36 — que torna
     * visível a distinção entre "Primeiro reforço" e "Reforço a cada 3 anos" na
     * carteira do tutor.
     */
    private const ESSENCIAL_FELINA = [
        'numero_doses_serie_primaria' => 3,
        'intervalo_minimo_dias' => 21,
        'intervalo_maximo_dias' => 28,
        'idade_minima_primeira_dose_semanas' => 6,
        'idade_minima_dose_final_semanas' => 16,
        'reforco_inicial_meses' => 6,
        'periodicidade_revacinacao_meses' => 36,
        'doses_adulto_sem_historico' => 2,
    ];

    /**
     * Antirrábica — dose única de série, revacinação anual.
     *
     * A WSAVA manda seguir a regulamentação local como prioridade, e só na
     * ausência dela a bula; há produtos com duração de imunidade declarada de um
     * e de três anos. No Brasil a prática é anual, e é ela que vale aqui — a
     * própria diretriz é quem determina essa ordem.
     *
     * A primeira dose não antes das 12 semanas é o que a tabela registra para o
     * uso corrente em vários países.
     */
    private const ANTIRRABICA = [
        'numero_doses_serie_primaria' => 1,
        'intervalo_minimo_dias' => 0,
        'intervalo_maximo_dias' => 0,
        'idade_minima_primeira_dose_semanas' => 12,
        'idade_minima_dose_final_semanas' => null,
        'reforco_inicial_meses' => null,
        'periodicidade_revacinacao_meses' => 12,
        'doses_adulto_sem_historico' => 1,
    ];

    /**
     * O padrão das não essenciais mortas e das bacterinas: duas doses para
     * imunizar, com 2 a 4 semanas entre elas, e reforço anual. É textualmente o
     * que a WSAVA responde para o adulto não vacinado — "as vacinas não
     * essenciais mortas exigiriam duas doses administradas com intervalos de 2 a
     * 4 semanas, com reforços anuais a partir de então".
     *
     * Sem idade mínima de dose final: a regra dos anticorpos maternos (RN33) é
     * das essenciais vivas, e aplicá-la aqui agendaria uma dose adicional que a
     * diretriz não pede.
     */
    private const NAO_ESSENCIAL_DUAS_DOSES = [
        'numero_doses_serie_primaria' => 2,
        'intervalo_minimo_dias' => 14,
        'intervalo_maximo_dias' => 28,
        'idade_minima_primeira_dose_semanas' => 8,
        'idade_minima_dose_final_semanas' => null,
        'reforco_inicial_meses' => null,
        'periodicidade_revacinacao_meses' => 12,
        'doses_adulto_sem_historico' => 2,
    ];

    public function run(): void
    {
        $encerrada = VersaoProtocolo::firstOrCreate(
            ['rotulo' => '2021.2'],
            [
                'situacao' => VersaoProtocolo::ENCERRADA,
                'base' => 'WSAVA 2015',
                'publicado_em' => '2021-04-05',
                'encerrado_em' => '2024-03-12',
            ],
        );

        $vigente = VersaoProtocolo::firstOrCreate(
            ['rotulo' => '2024.1'],
            [
                'situacao' => VersaoProtocolo::VIGENTE,
                'base' => 'WSAVA — 2024 guidelines for the vaccination of dogs and cats (VGG)',
                'publicado_em' => '2024-03-12',
                'encerrado_em' => null,
            ],
        );

        $rascunho = VersaoProtocolo::firstOrCreate(
            ['rotulo' => '2026.1'],
            [
                'situacao' => VersaoProtocolo::RASCUNHO,
                'base' => 'WSAVA 2024, com a revisão do intervalo mínimo da série canina para 3 semanas.',
                'publicado_em' => null,
                'encerrado_em' => null,
            ],
        );

        $itens = $this->semearCatalogo();

        // A 2021.2 cobre só o que o catálogo tinha em 2015, e exigia a dose
        // final com 14 semanas tolerando atraso maior. A diferença não é
        // decorativa: é o que o simulador de X02 compara quando se pede
        // "comparar com a versão vigente". Um item sem linha nesta versão
        // responde 422 na simulação, que é o comportamento certo — a versão não
        // tem o que dizer sobre o que não existia quando foi publicada.
        $this->parametrizar($encerrada, $itens['v10'], [...self::ESSENCIAL_CANINA, 'idade_minima_dose_final_semanas' => 14, 'limite_atraso_dias' => 45]);
        $this->parametrizar($encerrada, $itens['antirrabica'], self::ANTIRRABICA);
        $this->parametrizar($encerrada, $itens['triplice'], [...self::ESSENCIAL_FELINA, 'idade_minima_dose_final_semanas' => 14, 'reforco_inicial_meses' => 12, 'limite_atraso_dias' => 45]);

        // Todo item ativo precisa de linha na versão vigente. Sem ela,
        // `protocoloVigente()` devolve nulo, a aplicação é gravada com
        // `protocolo_vacinal_id` nulo e o grupo cai em "sem data suficiente para
        // calcular" na carteira do tutor — uma vacina no catálogo que não
        // produz lembrete algum.
        foreach ($this->parametrosPorChave() as $chave => $parametros) {
            $this->parametrizar($vigente, $itens[$chave], $parametros);

            // O rascunho é a vigente com a revisão que o seu texto de base
            // anuncia: intervalo mínimo de 3 semanas também na série canina,
            // alinhando-a à felina.
            $this->parametrizar($rascunho, $itens[$chave], $parametros['numero_doses_serie_primaria'] > 1
                ? [...$parametros, 'intervalo_minimo_dias' => 21]
                : $parametros);
        }

        // Administração da plataforma (X01, X02) — papel global (RF23,
        // RF24), sem vínculo de prestador nem cadastro de tutor. Conta
        // própria, para que a fatia possa ser vista sem exigir login por
        // formulário de conta alheia. `admin_plataforma` fica fora do
        // #[Fillable] do model (só a sessão decide quem tem o papel, nunca
        // um formulário) e por isso é atribuído à força, como `ativado_em`
        // em PrestadorController.
        //
        // Nunca em produção: a senha está no repositório, e quem a lesse
        // alteraria o catálogo de vacinas de todos. Lá a administração nasce
        // de ADMIN_PLATAFORMA_EMAIL, pelo comando `imunia:preparar`.
        if (! app()->isProduction()) {
            $adminPlataforma = User::firstOrCreate(
                ['email' => 'admin.plataforma@imunia.app'],
                ['name' => 'Administração Imunia', 'password' => 'Segredo123'],
            );
            $adminPlataforma->forceFill(['admin_plataforma' => true, 'email_verified_at' => now()])->save();
        }

        $this->command?->info(
            'Catálogo pronto: '.count($itens).' imunobiológicos da WSAVA 2024 '
            .'(essenciais e não essenciais, cão e gato), sob as versões 2021.2, 2024.1 e o rascunho 2026.1.',
        );
    }

    /**
     * O catálogo, indexado pela chave curta que a matriz de parâmetros usa.
     *
     * @return array<string, Imunobiologico>
     */
    private function semearCatalogo(): array
    {
        return [
            'v8' => $this->item('v8-multipla-canina', [
                'nome_comercial' => 'V8 múltipla canina',
                'nome_tecnico' => 'Polivalente canina — CDV, CAV-2, CPV-2 (vivos modificados), parainfluenza e Leptospira (bacterina)',
                'fabricante' => 'Zoetis',
                'agentes_cobertos' => 'cinomose, hepatite infecciosa, parvovirose, parainfluenza, leptospirose',
                'especie_destino' => 'cao',
                'classificacao' => 'essencial',
            ]),

            'v10' => $this->item('v10-multipla-canina', [
                'nome_comercial' => 'V10 múltipla canina',
                'nome_tecnico' => 'Polivalente canina — CDV, CAV-2, CPV-2 (vivos modificados), parainfluenza e Leptospira (bacterina, quatro sorogrupos)',
                'fabricante' => 'Zoetis',
                'agentes_cobertos' => 'cinomose, hepatite infecciosa, parvovirose, parainfluenza, leptospirose',
                'especie_destino' => 'cao',
                'classificacao' => 'essencial',
            ]),

            'antirrabica' => $this->item('antirrabica', [
                'nome_comercial' => 'antirrábica',
                'nome_tecnico' => 'Vacina antirrábica inativada',
                'fabricante' => 'MSD',
                'agentes_cobertos' => 'raiva',
                'especie_destino' => 'ambas',
                'classificacao' => 'essencial',
            ]),

            // A WSAVA a chama de "essencial para cães em regiões onde a
            // leptospirose canina é endêmica" — o que descreve o Brasil. Existe
            // isolada no catálogo, e não só dentro dos polivalentes, porque o
            // reforço dela é anual mesmo quando o núcleo viral não é.
            'leptospirose' => $this->item('leptospirose-canina', [
                'nome_comercial' => 'leptospirose canina',
                'nome_tecnico' => 'Bacterina de Leptospira spp. — sorogrupos conforme a região',
                'fabricante' => 'MSD',
                'agentes_cobertos' => 'leptospirose',
                'especie_destino' => 'cao',
                'classificacao' => 'essencial',
            ]),

            'tosse_intranasal' => $this->item('tosse-dos-canis-intranasal', [
                'nome_comercial' => 'tosse dos canis (intranasal)',
                'nome_tecnico' => 'Bordetella bronchiseptica avirulenta viva ± parainfluenza canina, via mucosa',
                'fabricante' => 'Zoetis',
                'agentes_cobertos' => 'traqueobronquite infecciosa canina (Bordetella bronchiseptica, parainfluenza)',
                'especie_destino' => 'cao',
                'classificacao' => 'nao_essencial',

                // A diretriz é explícita: estas vacinas de mucosa NÃO devem ser
                // injetadas por via parenteral, sob risco de reação adversa
                // grave. A via usual do catálogo não tem valor "intranasal", e
                // registrar "Subcutânea" aqui seria pré-preencher V07 com o erro
                // que a diretriz manda evitar — a via fica no nome, onde quem
                // aplica a lê.
                'via_administracao_usual' => 'Intramuscular',
            ]),

            'tosse_injetavel' => $this->item('tosse-dos-canis-injetavel', [
                'nome_comercial' => 'tosse dos canis (injetável)',
                'nome_tecnico' => 'Bordetella bronchiseptica — bacterina morta ou subunidade, parenteral',
                'fabricante' => 'Zoetis',
                'agentes_cobertos' => 'traqueobronquite infecciosa canina (Bordetella bronchiseptica)',
                'especie_destino' => 'cao',
                'classificacao' => 'nao_essencial',
            ]),

            'leishmaniose' => $this->item('leishmaniose-canina', [
                'nome_comercial' => 'leishmaniose canina',
                'nome_tecnico' => 'Proteína A2 recombinante de Leishmania, parenteral',
                'fabricante' => 'Ceva',
                'agentes_cobertos' => 'leishmaniose visceral canina',
                'especie_destino' => 'cao',
                'classificacao' => 'nao_essencial',
            ]),

            'triplice' => $this->item('triplice-felina', [
                'nome_comercial' => 'tríplice felina',
                'nome_tecnico' => 'Trivalente felina — FPV, FHV-1 e FCV, viva atenuada, parenteral',
                'fabricante' => 'Zoetis',
                'agentes_cobertos' => 'panleucopenia, rinotraqueíte (herpesvírus felino-1), calicivirose',
                'especie_destino' => 'gato',
                'classificacao' => 'essencial',
            ]),

            'quadrupla' => $this->item('quadrupla-felina', [
                'nome_comercial' => 'quádrupla felina',
                'nome_tecnico' => 'Tetravalente felina — FPV, FHV-1, FCV e vírus da leucemia felina',
                'fabricante' => 'MSD',
                'agentes_cobertos' => 'panleucopenia, rinotraqueíte, calicivirose, leucemia felina',
                'especie_destino' => 'gato',
                'classificacao' => 'essencial',
            ]),

            // A WSAVA 2024 traz o FeLV sob "vacinas essenciais para gatos": é
            // essencial para o gato com menos de um ano em região onde a
            // infecção é prevalente, e para o adulto com risco continuado de
            // exposição. Só gatos FeLV negativos devem ser vacinados, e o teste
            // precede a aplicação — o que é conduta clínica, e o sistema não
            // tem como exigir (RN36).
            'leucemia' => $this->item('leucemia-felina', [
                'nome_comercial' => 'leucemia felina',
                'nome_tecnico' => 'Vírus da leucemia felina — recombinante ou inativada, com adjuvante',
                'fabricante' => 'Zoetis',
                'agentes_cobertos' => 'leucemia felina',
                'especie_destino' => 'gato',
                'classificacao' => 'essencial',
            ]),

            'clamidiose' => $this->item('clamidiose-felina', [
                'nome_comercial' => 'clamidiose felina',
                'nome_tecnico' => 'Chlamydia felis — viva avirulenta ou morta com adjuvante, parenteral',
                'fabricante' => 'Zoetis',
                'agentes_cobertos' => 'clamidiose felina (Chlamydia felis)',
                'especie_destino' => 'gato',
                'classificacao' => 'nao_essencial',
            ]),

            // Entra **inativa**, e é o único item do catálogo nessa situação.
            //
            // A giárdia é vendida no Brasil e a WSAVA a lista entre as **não
            // recomendadas**: não há evidência de que previna a eliminação de
            // cistos, e o cão vacinado pode adoecer. Deixá-la fora do catálogo
            // esconderia a decisão; deixá-la ativa a ofereceria em V07 como se a
            // diretriz a admitisse. Inativa, ela diz as duas coisas: existe, e
            // não se registra — que é exatamente o que RF23b desenhou, e o que
            // dá a X01 um caso real de inativação para demonstrar.
            'giardia' => $this->item('giardia-canina', [
                'nome_comercial' => 'giárdia canina',
                'nome_tecnico' => 'Giardia spp. — não recomendada pela WSAVA 2024 por ausência de evidência de eficácia',
                'fabricante' => 'Zoetis',
                'agentes_cobertos' => 'giardíase',
                'especie_destino' => 'cao',
                'classificacao' => 'nao_essencial',
                'ativo' => false,
            ]),
        ];
    }

    /**
     * A matriz de parâmetros da versão vigente, por chave do catálogo. Só os
     * itens ativos aparecem: a giárdia não tem cálculo porque não se registra.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parametrosPorChave(): array
    {
        return [
            'v8' => self::ESSENCIAL_CANINA,
            'v10' => self::ESSENCIAL_CANINA,
            'antirrabica' => self::ANTIRRABICA,
            'leptospirose' => self::NAO_ESSENCIAL_DUAS_DOSES,
            'tosse_injetavel' => self::NAO_ESSENCIAL_DUAS_DOSES,
            'clamidiose' => [...self::NAO_ESSENCIAL_DUAS_DOSES, 'idade_minima_primeira_dose_semanas' => 9],

            // Dose única por via de mucosa protege após uma aplicação, e o
            // reforço é anual.
            'tosse_intranasal' => [
                ...self::NAO_ESSENCIAL_DUAS_DOSES,
                'numero_doses_serie_primaria' => 1,
                'intervalo_minimo_dias' => 0,
                'intervalo_maximo_dias' => 0,
                'doses_adulto_sem_historico' => 1,
            ],

            // Três doses com 3 semanas de intervalo, a primeira a partir dos 4
            // meses de idade, reforço anual. A vacinação é medida suplementar, e
            // não substitui o controle dos flebotomíneos.
            'leishmaniose' => [
                ...self::NAO_ESSENCIAL_DUAS_DOSES,
                'numero_doses_serie_primaria' => 3,
                'intervalo_minimo_dias' => 21,
                'intervalo_maximo_dias' => 21,
                'idade_minima_primeira_dose_semanas' => 17,
                'doses_adulto_sem_historico' => 3,
            ],

            'triplice' => self::ESSENCIAL_FELINA,

            // A quádrupla carrega o componente de leucemia felina, cujo reforço
            // é anual no gato de risco continuado — e é o prazo mais curto que
            // rege o produto, como a leptospirose rege os polivalentes caninos.
            // A primeira dose sobe para 8 semanas, que é quando o FeLV começa.
            'quadrupla' => [
                ...self::ESSENCIAL_FELINA,
                'idade_minima_primeira_dose_semanas' => 8,
                'reforco_inicial_meses' => 12,
                'periodicidade_revacinacao_meses' => 12,
            ],

            // Duas doses com 3 a 4 semanas a partir das 8 semanas, revacinação
            // um ano após a última dose da série e, depois, anual para o gato
            // com risco continuado de exposição.
            'leucemia' => [
                ...self::NAO_ESSENCIAL_DUAS_DOSES,
                'intervalo_minimo_dias' => 21,
                'intervalo_maximo_dias' => 28,
                'reforco_inicial_meses' => 12,
            ],
        ];
    }

    /**
     * `updateOrCreate` e não `firstOrCreate`: o seeder é a fonte da diretriz, e
     * uma revisão da WSAVA que mude um texto ou um parâmetro tem de chegar a
     * quem já tem banco semeado. A chave é o identificador estável (RN30) e
     * nunca muda.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function item(string $chave, array $atributos): Imunobiologico
    {
        return Imunobiologico::updateOrCreate(['chave' => $chave], [
            'via_administracao_usual' => 'Subcutânea',
            'ativo' => true,
            ...$atributos,
        ]);
    }

    /**
     * @param  array<string, mixed>  $parametros
     */
    private function parametrizar(VersaoProtocolo $versao, Imunobiologico $imunobiologico, array $parametros): void
    {
        ProtocoloVacinal::updateOrCreate(
            ['versao_protocolo_id' => $versao->id, 'imunobiologico_id' => $imunobiologico->id],
            $parametros,
        );
    }
}
