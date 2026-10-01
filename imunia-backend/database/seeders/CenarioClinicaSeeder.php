<?php

namespace Database\Seeders;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Imunobiologico;
use App\Models\Notificacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use App\Services\CalendarioVacinalService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A rotina da Clínica Vet Amigo vista do lado de dentro — o que V01 precisa
 * mostrar e o cenário do tutor, sozinho, não produz: um plantel de animais
 * atendidos nas últimas semanas, doses vencidas de tutores diferentes e
 * retornos programados para os próximos dias.
 *
 * Todas as datas são relativas a hoje, e não fixas como as do cenário canônico
 * (§8.2 do briefing): o painel do veterinário é uma janela móvel de trinta dias,
 * e um seeder com datas fixas deixaria de povoá-la um mês depois de escrito. O
 * histórico do Théo permanece intocado — aquelas datas são citadas nos
 * documentos e sustentam T05 a T08.
 *
 * Semeia também o segundo vínculo do Dr. Marcelo, com o Hospital Bicho Bom, que
 * acompanha um animal só: um contexto pequeno, para o alternador de prestador
 * ter entre o que alternar.
 */
class CenarioClinicaSeeder extends Seeder
{
    private const SENHA = 'Segredo123';

    public function run(): void
    {
        $clinica = Prestador::where('cnpj', '11222333000181')->firstOrFail();
        $marcelo = User::where('email', 'marcelo.andrade@vetamigo.example.com')->firstOrFail();

        // O cenário canônico: a clínica acompanha o Théo, de quem o Dr. Marcelo
        // já cuidava. É o vínculo que o traz ao painel.
        $theo = Animal::where('nome', 'Théo')->firstOrFail();
        $clinica->vincular($theo);

        // A Nina entra pelo estado que nenhum outro animal do cenário produz e
        // que V06 precisa desenhar: cadastro ainda preliminar (RN17 — Helena a
        // cadastrou, nenhum veterinário a caracterizou).
        $nina = Animal::where('nome', 'Nina')->firstOrFail();
        $clinica->vincular($nina);

        $this->plantelDaClinica($clinica, $marcelo);
        $this->segundoContextoDoMarcelo($marcelo);
        $this->registroDeOutroPrestador($theo);
        $this->profissionalAutonoma();

        $this->command?->info(sprintf(
            'Cenário da clínica pronto: %s / %s — %d animais acompanhados na %s.',
            $marcelo->email,
            self::SENHA,
            Animal::query()->vinculadoA($clinica)->count(),
            $clinica->nome,
        ));
    }

    /**
     * Os animais que a clínica acompanha, cada um em um estado diferente do
     * calendário — porque é a variedade deles que a tela precisa desenhar, e um
     * plantel inteiro em dia não exercitaria linha alguma da rechamada.
     */
    private function plantelDaClinica(Prestador $clinica, User $marcelo): void
    {
        $hoje = CarbonImmutable::today();

        // Mel, gata do Antônio: série primária completa, reforço anual caindo na
        // semana que vem. É a pendência "próxima" do painel.
        $mel = $this->animal('Mel', 'gato', 'femea', $hoje->subYears(3), 'Antônio Prado', '23847190504');
        $this->serieCompleta($mel, $clinica, 'triplice-felina', $hoje->subYear()->addDays(9), $marcelo);
        $this->atendimento($mel, $clinica, $marcelo, $hoje->subDays(15), 'Consulta de rotina', [
            'motivo' => 'Avaliação anual e revisão do calendário vacinal.',
            'conduta' => 'Manter a alimentação atual. Reforço da tríplice na próxima semana.',
        ]);

        // Bidu, cão do mesmo tutor: em dia, com reforço aplicado há pouco. Serve
        // para que a tabela não seja só uma lista de problemas.
        $bidu = $this->animal('Bidu', 'cao', 'macho', $hoje->subYears(4), 'Antônio Prado', '23847190504');
        $this->serieCompleta($bidu, $clinica, 'v10-multipla-canina', $hoje->subDays(400), $marcelo);
        $this->dose($bidu, $clinica, 'v10-multipla-canina', $hoje->subDays(17), 4, $marcelo);

        // Frida, gata da Marina: em dia, e com retorno programado (RF34) para
        // daqui a cinco dias — o primeiro item do bloco de retornos.
        $frida = $this->animal('Frida', 'gato', 'femea', $hoje->subYears(2), 'Marina Duarte', '50681347244');
        $this->serieCompleta($frida, $clinica, 'triplice-felina', $hoje->subMonths(6), $marcelo);
        $this->atendimento($frida, $clinica, $marcelo, $hoje->subDays(20), 'Dermatite', [
            'motivo' => 'Descamação e prurido na região do pescoço.',
            'conduta' => 'Xampu específico duas vezes por semana. Reavaliação em três semanas.',
            'retorno_em' => $hoje->addDays(5),
            'retorno_finalidade' => 'Reavaliação dermatológica',
        ]);

        // Kiko, filhote do Ruan: primeira dose aplicada, segunda prevista para
        // daqui a três dias, com retorno marcado para o mesmo dia.
        $kiko = $this->animal('Kiko', 'cao', 'macho', $hoje->subWeeks(9), 'Ruan Teixeira', '72910463869');
        $this->dose($kiko, $clinica, 'v10-multipla-canina', $hoje->subDays(18), 1, $marcelo);
        $this->atendimento($kiko, $clinica, $marcelo, $hoje->subDays(18), 'Primeira consulta do filhote', [
            'motivo' => 'Início do protocolo vacinal e avaliação geral.',
            'conduta' => 'Segunda dose em três semanas. Vermifugação conforme receita.',
            'retorno_em' => $hoje->addDays(3),
            'retorno_finalidade' => 'Segunda dose da série',
        ]);

        // Amendoim e Zeca completam a semana da clínica. Existem para que a
        // tabela passe de cinco linhas: a paginação de V01 não se demonstra com
        // uma página só, e um painel que nunca precisa dela foi desenhado para
        // uma clínica que não existe.
        $amendoim = $this->animal('Amendoim', 'gato', 'macho', $hoje->subYears(5), 'Marina Duarte', '50681347244');
        $this->serieCompleta($amendoim, $clinica, 'triplice-felina', $hoje->subDays(8), $marcelo);

        $zeca = $this->animal('Zeca', 'cao', 'macho', $hoje->subYears(6), 'Ruan Teixeira', '72910463869');
        $this->serieCompleta($zeca, $clinica, 'v10-multipla-canina', $hoje->subMonths(5), $marcelo);
        $this->atendimento($zeca, $clinica, $marcelo, $hoje->subDays(3), 'Claudicação', [
            'motivo' => 'Apoio reduzido no membro posterior direito após corrida no parque.',
            'conduta' => 'Repouso por dez dias e anti-inflamatório conforme receita.',
        ]);

        // Pipoca, cadela da Sofia: série interrompida, terceira dose vencida há
        // quatro semanas. Não aparece entre os atendidos recentemente — o último
        // registro dela é anterior à janela —, e é exatamente por isso que
        // precisa aparecer nas pendências: ninguém a traria de volta sem alguém
        // perguntar por ela. A tutora nunca ativou o acesso: é o estado que
        // RF14b manda sinalizar, e a razão pela qual o lembrete de RF42 não
        // chegou a ela — a rechamada por telefone é o que resta.
        $pipoca = $this->animal(
            'Pipoca', 'cao', 'femea', $hoje->subMonths(8), 'Sofia Nunes', '31847290523',
            tutorAtivado: false,
        );
        $this->dose($pipoca, $clinica, 'v10-multipla-canina', $hoje->subDays(70), 1, $marcelo);
        $this->dose($pipoca, $clinica, 'v10-multipla-canina', $hoje->subDays(49), 2, $marcelo);

        // Tobias, cão do Antônio: óbito registrado no mês passado. Existe para
        // que V06 tenha o estado de animal inativo (RF22) — histórico
        // consultável, calendário encerrado e nenhuma ação de registro clínico
        // disponível. Um animal novo, e não um dos de cima, porque o óbito
        // silencia o calendário e retiraria da rechamada de V02 um caso que ela
        // precisa continuar exercitando.
        $tobias = $this->animal('Tobias', 'cao', 'macho', $hoje->subYears(13), 'Antônio Prado', '23847190504');
        $this->serieCompleta($tobias, $clinica, 'v10-multipla-canina', $hoje->subMonths(14), $marcelo);
        $this->atendimento($tobias, $clinica, $marcelo, $hoje->subMonths(2), 'Insuficiência renal crônica', [
            'motivo' => 'Apatia progressiva, perda de peso e aumento da ingestão de água.',
            'diagnostico' => 'Doença renal crônica em estágio avançado.',
            'conduta' => 'Fluidoterapia, dieta renal e reavaliação semanal.',
        ]);
        $tobias->forceFill([
            'obito_em' => $hoje->subMonth()->toDateString(),
            'obito_registrado_por_user_id' => $marcelo->id,
        ])->save();

        // A carteira da clínica (RN48): é o vínculo que os traz para o painel.
        foreach ([$mel, $bidu, $frida, $kiko, $amendoim, $zeca, $pipoca, $tobias] as $animal) {
            $clinica->vincular($animal, Prestador::VINCULO_POR_CADASTRO);
        }

        // A coluna de V02 que decide a rechamada (RF49): quem já foi avisado, e
        // com que resultado. Os três estados precisam existir no cenário, porque
        // é a diferença entre eles que muda a conduta da equipe — e um plantel
        // em que todos foram avisados não exercitaria a tela.
        $this->notificar($pipoca, 'alerta_atraso', 'entregue');     // avisado, e mesmo assim não voltou
        $this->notificar($kiko, 'aviso_previo', 'entregue');        // avisado a tempo
        $this->notificar($mel, 'aviso_previo', 'sem_confirmacao');  // a mensagem saiu; ninguém confirmou
        // O Théo fica sem notificação alguma: é o "nunca notificado" da tela, e
        // o caso em que o telefonema é a única providência que resta.
    }

    /**
     * Registra o aviso que teria sido emitido para a próxima dose prevista do
     * animal (RF42, RN43). A data de referência sai do próprio cálculo do
     * calendário, e não de um número escrito à mão: assim o cenário continua
     * coerente quando o protocolo mudar de parâmetro.
     */
    private function notificar(Animal $animal, string $tipo, string $situacao): void
    {
        $proxima = collect(app(CalendarioVacinalService::class)->montarCarteira($animal)['proximas_doses'])
            ->first();

        if ($proxima === null) {
            return;
        }

        $imunobiologico = Imunobiologico::where('chave', $proxima['imunobiologico_chave'])->first();

        Notificacao::firstOrCreate(
            [
                'animal_id' => $animal->id,
                'imunobiologico_id' => $imunobiologico?->id,
                'tipo' => $tipo,
                'referente_a' => $proxima['prevista_para'],
            ],
            [
                'tutor_id' => $animal->tutor_id,
                'destinatario' => $animal->tutor->user->email,
                // O aviso prévio sai antes da data; o alerta de atraso, depois
                // dela (RN44). Em ambos os casos a data de envio é o que a tela
                // exibe, e ela precisa fazer sentido ao lado da data prevista.
                //
                // O teto em "hoje" não é detalhe de cenário: uma notificação
                // com data de envio no futuro é registro de algo que não
                // aconteceu, e a coluna de V02 passaria a afirmar que o tutor
                // foi avisado numa data que ainda não chegou. Para a dose que
                // vence dentro de sete dias, o aviso prévio é o de hoje.
                'enviada_em' => min(
                    $tipo === 'alerta_atraso'
                        ? CarbonImmutable::parse($proxima['prevista_para'])->addDays(3)->setTime(8, 0)
                        : CarbonImmutable::parse($proxima['prevista_para'])->subDays(7)->setTime(8, 0),
                    CarbonImmutable::today()->setTime(8, 0),
                ),
                'situacao' => $situacao,
            ],
        );
    }

    /**
     * O atendimento que o Théo recebeu em outra clínica, e que a Clínica Vet
     * Amigo enxerga porque o histórico do animal é um só (RF35).
     *
     * É o caso central de V06 e o que o cenário não tinha: sem registro de
     * prestador distinto do ativo, o aviso de RF52b — "sua visualização é
     * registrada e fica visível ao tutor" — nunca apareceria, e a linha de log
     * que RN49 exige não teria o que registrar. É também o episódio de P6, a
     * descontinuidade do cuidado que o sistema existe para resolver: o segundo
     * profissional só não repete a investigação porque lê a do primeiro.
     *
     * O Pet Center não recebe vínculo de veterinário algum do cenário: é um
     * prestador de fora, cujo registro chega à clínica pelo histórico do animal
     * e não por acesso de quem o produziu.
     */
    private function registroDeOutroPrestador(Animal $theo): void
    {
        $petCenter = Prestador::firstOrCreate(
            ['cnpj' => '04731582000137'],
            [
                'tipo' => 'clinica',
                'nome' => 'Pet Center Zona Sul',
                'telefone' => '(31) 3298-7710',
                'endereco' => 'Rua dos Ipês, 145',
                'municipio' => 'Belo Horizonte',
                'uf' => 'MG',
                'responsavel_tecnico_nome' => 'Beatriz Salles',
                'responsavel_tecnico_crmv' => '18220',
                'responsavel_tecnico_crmv_uf' => 'MG',
            ],
        );

        // A veterinária do Pet Center existe como autora do registro, não como
        // usuária: RN27 manda preservar a autoria, e ela é preservada pelo nome
        // e pelo CRMV gravados na própria linha do atendimento.
        $beatriz = User::firstOrCreate(
            ['email' => 'beatriz.salles@petcenter.example.com'],
            ['name' => 'Beatriz Salles', 'password' => self::SENHA],
        );

        $this->atendimento(
            $theo,
            $petCenter,
            $beatriz,
            CarbonImmutable::parse('2025-12-04'),
            'Claudicação leve',
            [
                'motivo' => 'Apoio reduzido no membro pélvico direito após queda do sofá.',
                'diagnostico' => 'Contusão sem sinais de fratura.',
                'conduta' => 'Repouso por sete dias e reavaliação se persistir.',
            ],
        );

        Atendimento::where('animal_id', $theo->id)
            ->where('prestador_id', $petCenter->id)
            ->update(['profissional_nome' => 'Dra. Beatriz Salles', 'profissional_crmv' => 'CRMV-MG 18220']);
    }

    /**
     * A profissional autônoma. Existe no cenário por um motivo só: dos três
     * tipos que RF07 admite, o autônomo é o único que nenhum outro seeder
     * produz, e é dele que sai o rótulo "Atendimento domiciliar" — sem ele,
     * RF07a fica sem demonstração. Acompanha a Nina, que também é atendida em
     * casa.
     */
    private function profissionalAutonoma(): void
    {
        $larissa = Prestador::firstOrCreate(
            ['cnpj' => '26937175000113'],
            [
                'tipo' => 'autonomo',
                'nome' => 'Dra. Larissa Prado',
                'telefone' => '(31) 99712-0184',
                'endereco' => 'Rua Padre Serafim, 45',
                'municipio' => 'Viçosa',
                'uf' => 'MG',
                'responsavel_tecnico_nome' => 'Larissa Prado',
                'responsavel_tecnico_crmv' => '24196',
                'responsavel_tecnico_crmv_uf' => 'MG',
            ],
        );

        $larissa->vincular(Animal::where('nome', 'Nina')->firstOrFail());
    }

    /**
     * O segundo vínculo do Dr. Marcelo: o Hospital Bicho Bom, que acompanha só a
     * Amora, vacinada lá há quatro meses.
     */
    private function segundoContextoDoMarcelo(User $marcelo): void
    {
        $hospital = Prestador::firstOrCreate(
            ['cnpj' => '35820914000183'],
            [
                'tipo' => 'hospital',
                'nome' => 'Hospital Bicho Bom',
                'telefone' => '(31) 3712-4400',
                'endereco' => 'Avenida Castelo Branco, 980',
                'municipio' => 'Viçosa',
                'uf' => 'MG',
                'responsavel_tecnico_nome' => 'Renata Vasconcelos',
                'responsavel_tecnico_crmv' => '20871',
                'responsavel_tecnico_crmv_uf' => 'MG',
            ],
        );

        $marcelo->prestadores()->syncWithoutDetaching([
            $hospital->id => ['papel' => 'veterinario', 'crmv' => '12345', 'crmv_uf' => 'MG'],
        ]);

        $hoje = CarbonImmutable::today();

        $amora = $this->animal('Amora', 'cao', 'femea', $hoje->subYears(5), 'Beatriz Salles', '64510238762');
        $this->dose($amora, $hospital, 'antirrabica', $hoje->subMonths(4), 1, $marcelo);
        $hospital->vincular($amora);
    }

    /**
     * Tutor com acesso ativo e animal sob sua titularidade. O tutor nasce com
     * usuário porque `tutores` exige um: o cadastro feito pelo veterinário (RF12)
     * só chega a este estado depois do convite de ativação (RF14), e o cenário
     * parte de quem já ativou.
     */
    private function animal(
        string $nome,
        string $especie,
        string $sexo,
        CarbonImmutable $nascimento,
        string $nomeDoTutor,
        string $cpf,
        bool $tutorAtivado = true,
    ): Animal {
        $tutor = Tutor::firstWhere('cpf', $cpf);

        if ($tutor === null) {
            $usuario = User::firstOrCreate(
                ['email' => $this->email($nomeDoTutor)],
                ['name' => $nomeDoTutor, 'password' => self::SENHA],
            );

            // RF14 — o carimbo separa quem já definiu a própria senha de quem
            // recebeu o convite e nunca o abriu. As telas do prestador
            // sinalizam o segundo caso (RF14b), e sem ao menos um tutor em cada
            // estado a sinalização não teria o que demonstrar.
            $usuario->forceFill([
                'email_verified_at' => $usuario->email_verified_at ?? now(),
                'ativado_em' => $tutorAtivado ? ($usuario->ativado_em ?? now()) : null,
            ])->save();

            $tutor = Tutor::create([
                'user_id' => $usuario->id,
                'nome' => $nomeDoTutor,
                'cpf' => $cpf,
                'termos_aceitos_em' => now(),
            ]);
        }

        $animal = Animal::firstOrCreate(
            ['tutor_id' => $tutor->id, 'nome' => $nome],
            [
                'especie' => $especie,
                'sexo' => $sexo,
                'nascimento_em' => $nascimento->toDateString(),
                'nascimento_exato' => true,
            ],
        );

        // RF19 — todos passaram por consulta, e a caracterização é ato do
        // veterinário. Forçado porque não é atribuível em massa de propósito.
        // O micro-chip entra junto: é uma das quatro chaves de busca de RF51, e
        // sem valor semeado a busca por ele não teria o que demonstrar. Prefixo
        // 076, o código do país na ISO 11784.
        $animal->forceFill([
            'caracterizado_em' => $animal->caracterizado_em ?? now(),
            'microchip' => $animal->microchip ?? sprintf('076%012d', $animal->id),
        ])->save();

        return $animal;
    }

    /**
     * Série primária completa de um imunobiológico, terminando na data informada.
     * As doses anteriores recuam pelo intervalo previsto no protocolo, para que o
     * cálculo do calendário (RF26) reconheça a série como concluída e passe a
     * contar o reforço.
     */
    private function serieCompleta(
        Animal $animal,
        Prestador $prestador,
        string $chave,
        CarbonImmutable $ultima,
        User $aplicador,
    ): void {
        $imunobiologico = Imunobiologico::where('chave', $chave)->firstOrFail();
        $intervalo = $imunobiologico->protocoloVigente()->intervaloPrevistoDias();
        $doses = $imunobiologico->protocoloVigente()->numero_doses_serie_primaria;

        for ($ordem = 1; $ordem <= $doses; $ordem++) {
            $this->dose(
                $animal,
                $prestador,
                $chave,
                $ultima->subDays($intervalo * ($doses - $ordem)),
                $ordem,
                $aplicador,
            );
        }
    }

    private function dose(
        Animal $animal,
        Prestador $prestador,
        string $chave,
        CarbonImmutable $em,
        int $ordem,
        User $aplicador,
    ): void {
        $imunobiologico = Imunobiologico::where('chave', $chave)->firstOrFail();

        Vacinacao::firstOrCreate(
            [
                'animal_id' => $animal->id,
                'imunobiologico_id' => $imunobiologico->id,
                'ordem_dose' => $ordem,
            ],
            [
                'prestador_id' => $prestador->id,
                'protocolo_vacinal_id' => $imunobiologico->protocoloVigente()->id,
                'origem' => 'profissional',
                'fabricante' => 'Zoetis',
                'lote' => sprintf('L%02d-%04d', $ordem, 1000 + $animal->id * 7),
                'validade' => $em->addYears(2)->toDateString(),
                'via_administracao' => 'Subcutânea',
                'aplicado_em' => $em->setTime(9, 30),
                'data_aproximada' => false,
                'aplicador_nome' => 'Dr. Marcelo Andrade',
                'aplicador_crmv' => 'CRMV-MG 12345',

                // RN27 — o nome no registro é o retrato do que valia no dia; é
                // esta coluna que responde "quem aplicou" quando V09 pergunta
                // quem pode retificar. Sem ela, o cenário de demonstração
                // afirma um aplicador que o sistema não sabe identificar, e
                // nenhuma aplicação aparece retificável para ninguém.
                'aplicador_user_id' => $aplicador->id,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function atendimento(
        Animal $animal,
        Prestador $prestador,
        User $profissional,
        CarbonImmutable $em,
        string $titulo,
        array $dados,
    ): void {
        Atendimento::firstOrCreate(
            ['animal_id' => $animal->id, 'atendido_em' => $em->setTime(14, 0)],
            [
                'prestador_id' => $prestador->id,
                'titulo' => $titulo,
                'motivo' => $dados['motivo'],
                'anamnese' => 'Tutor relata boa disposição e apetite preservado.',
                'exame_fisico' => 'Parâmetros vitais dentro da normalidade. Mucosas normocoradas.',
                'hipoteses_diagnosticas' => 'Sem alterações que justifiquem investigação adicional.',
                'diagnostico' => $dados['diagnostico'] ?? 'Animal hígido.',
                'conduta' => $dados['conduta'],
                'profissional_user_id' => $profissional->id,
                'profissional_nome' => 'Dr. Marcelo Andrade',
                'profissional_crmv' => 'CRMV-MG 12345',
                'retorno_em' => isset($dados['retorno_em']) ? $dados['retorno_em']->toDateString() : null,
                'retorno_finalidade' => $dados['retorno_finalidade'] ?? null,
            ],
        );
    }

    private function email(string $nome): string
    {
        return Str::slug($nome, '.').'@example.com';
    }
}
