<?php

namespace Database\Seeders;

use App\Models\AnexoAtendimento;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use App\Models\Vacinacao;
use Database\Seeders\Support\ArquivoDeDemonstracao;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cenário canônico do projeto — a tutora Helena Ramos, o cão Théo e a gata Nina
 * (§2.2 dos requisitos e §8.2 do briefing de design). Existe para que as telas
 * do tutor possam ser vistas com conteúdo enquanto o cadastro de animais (T03)
 * não foi construído; assim que ele existir, este seeder deixa de ser a única
 * forma de povoar o painel, mas continua servindo à demonstração à banca.
 */
class CenarioDemonstracaoSeeder extends Seeder
{
    private const CPF = '52998224725';

    private const EMAIL = 'helena.ramos@example.com';

    private const SENHA = 'Segredo123';

    public function run(): void
    {
        $this->call(CatalogoImunobiologicosSeeder::class);

        $tutor = $this->tutora();

        // Théo foi atendido na Clínica Vet Amigo: um veterinário já completou a
        // caracterização, e o cadastro deixou de ser preliminar (RF19). O campo
        // é forçado porque não é atribuível em massa — preenchê-lo é ato
        // privativo do veterinário, e o seeder está imitando esse ato.
        $theo = Animal::firstOrCreate(
            ['tutor_id' => $tutor->id, 'nome' => 'Théo'],
            [
                'especie' => 'cao',
                'sexo' => 'macho',
                'nascimento_em' => '2025-02-04',
                'nascimento_exato' => true,
            ],
        );
        // O micro-chip é uma das quatro chaves da busca do veterinário (RF51,
        // V03) e, como a caracterização a que pertence, é escrito à força:
        // preenchê-lo é ato privativo do veterinário (RF19) e não é atribuível
        // em massa. Prefixo 076, o código do país na ISO 11784.
        $theo->forceFill([
            'caracterizado_em' => now(),
            'microchip' => $theo->microchip ?? sprintf('076%012d', $theo->id),
        ])->save();

        // Nina foi adotada adulta e cadastrada pela própria Helena: sem data de
        // nascimento conhecida e ainda preliminar (RN17). Fica sem vacinação
        // alguma de propósito — é o estado vazio de T05 (RF28).
        Animal::firstOrCreate(
            ['tutor_id' => $tutor->id, 'nome' => 'Nina'],
            [
                'especie' => 'gato',
                'sexo' => 'femea',
            ],
        );

        $clinica = $this->clinicaVetAmigo();

        $this->historicoVacinalDeTheo($theo, $clinica);
        $this->atendimentoDeTheo($theo, $clinica);

        $this->command?->info(sprintf(
            'Cenário de demonstração pronto: %s / %s — %d animais.',
            $tutor->user->email,
            self::SENHA,
            $tutor->animais()->count(),
        ));
    }

    /**
     * O CPF é a identificação única do tutor na plataforma (RN11), e não o
     * e-mail: se a base de desenvolvimento já tiver a Helena — cadastrada à mão
     * numa sessão anterior, por exemplo —, o seeder assume aquele cadastro em
     * vez de tentar um segundo, que a própria regra proíbe. Em qualquer dos
     * dois caminhos, a senha e a confirmação de endereço ficam conhecidas, sem
     * o que a conta não serviria para demonstrar tela alguma.
     */
    private function tutora(): Tutor
    {
        $tutor = Tutor::with('user')->firstWhere('cpf', self::CPF);

        $usuario = $tutor?->user ?? User::firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Helena Ramos', 'password' => self::SENHA],
        );

        $usuario->forceFill([
            'password' => self::SENHA,
            'email_verified_at' => $usuario->email_verified_at ?? now(),

            // RF14 — Helena definiu a própria senha, e é por isso que ela entra
            // no sistema em toda demonstração. Sem carimbar o momento, as telas
            // do prestador a exibiriam como tutora que nunca ativou o acesso
            // (RF14b), afirmação que o resto do cenário desmente. A omissão só
            // apareceu na fatia de V06, primeira tela a ler o campo.
            'ativado_em' => $usuario->ativado_em ?? now(),
        ])->save();

        return $tutor ?? Tutor::create([
            'user_id' => $usuario->id,
            'nome' => 'Helena Ramos',
            'cpf' => self::CPF,
            'termos_aceitos_em' => now(),
        ]);
    }

    /**
     * A clínica do cenário (§2.2 dos requisitos), inquilino de todo registro
     * clínico do Théo: as vacinações e o atendimento saem dela.
     */
    private function clinicaVetAmigo(): Prestador
    {
        return Prestador::firstOrCreate(
            ['cnpj' => '11222333000181'],
            [
                'tipo' => 'clinica',
                'nome' => 'Clínica Vet Amigo',
                'telefone' => '(31) 3899-1000',
                'endereco' => 'Rua dos Estudantes, 120',
                'municipio' => 'Viçosa',
                'uf' => 'MG',
                'responsavel_tecnico_nome' => 'Marcelo Andrade',
                'responsavel_tecnico_crmv' => '12345',
                'responsavel_tecnico_crmv_uf' => 'MG',
            ],
        );
    }

    /**
     * O veterinário autor do atendimento. Existe como usuário, e não apenas
     * como nome copiado no registro, porque é o vínculo dele com o prestador
     * que RN27 consulta para saber quem pode retificar — e porque sem usuário
     * não haveria a quem atribuir a autoria exigida por RF31.
     */
    private function veterinario(Prestador $clinica): User
    {
        $usuario = User::firstOrCreate(
            ['email' => 'marcelo.andrade@vetamigo.example.com'],
            ['name' => 'Marcelo Andrade', 'password' => self::SENHA],
        );

        $usuario->forceFill(['email_verified_at' => $usuario->email_verified_at ?? now()])->save();

        $usuario->prestadores()->syncWithoutDetaching([
            $clinica->id => ['papel' => 'veterinario', 'crmv' => '12345', 'crmv_uf' => 'MG'],
        ]);

        return $usuario;
    }

    /**
     * O atendimento do cenário do Claude Design (T08): a dermatite do Théo em
     * 12/11/2025, com três anexos, retorno programado para três dias depois
     * (RF34) e a retificação do diagnóstico quando saiu o resultado do raspado
     * (RF33) — que é o encadeamento que a tela precisa poder mostrar nos dois
     * sentidos.
     *
     * Corresponde ao episódio de novembro de 2025 do estudo de caso: é o
     * atendimento cujo laudo o segundo profissional não teria, e por isso
     * repetiria o exame.
     */
    private function atendimentoDeTheo(Animal $theo, Prestador $clinica): void
    {
        $veterinario = $this->veterinario($clinica);

        $comum = [
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'titulo' => 'Dermatite',
            'motivo' => 'Coceira intensa e falhas no pelo na região das costas, há cerca de duas semanas.',
            'anamnese' => 'Tutora relata início após passeios em área com mato alto. Sem mudança de '
                .'alimentação. Nenhum outro animal na casa. Théo dorme em ambiente interno.',
            'exame_fisico' => 'Peso 12,4 kg. Temperatura 38,6 °C. Lesões avermelhadas com descamação na '
                .'região dorsal, sem odor. Linfonodos normais.',
            'hipoteses_diagnosticas' => 'Dermatite alérgica por picada de ectoparasita; dermatite por ácaros.',
            'conduta' => 'Banho com xampu específico duas vezes por semana. Evitar a área de mato até o '
                .'retorno. Retorno em três dias com o resultado do exame.',
            'profissional_user_id' => $veterinario->id,
            'profissional_nome' => 'Dr. Marcelo Andrade',
            'profissional_crmv' => 'CRMV-MG 12345',
        ];

        $atendimento = Atendimento::firstOrCreate(
            ['animal_id' => $theo->id, 'atendido_em' => '2025-11-12 16:20:00'],
            [
                ...$comum,
                'diagnostico' => 'Aguardando resultado do raspado cutâneo coletado nesta consulta.',
                'retorno_em' => '2025-11-15',
                'retorno_finalidade' => 'Reavaliação com o resultado do raspado.',
            ],
        );

        $atendimento->forceFill(['created_at' => '2025-11-12 16:20:00'])->save();

        $this->anexosDoAtendimento($atendimento);

        // RF33 — o conteúdo corrigido entra como registro novo, com o motivo e a
        // autoria. Só o diagnóstico muda: é o que a tela exibe lado a lado com a
        // versão original, e o que ficou igual não vira ruído na comparação.
        //
        // `atendido_em` é o do original, e não o dia em que a correção foi
        // escrita: a consulta aconteceu em 12/11, e uma retificação com data
        // própria inventaria um segundo atendimento que ninguém prestou (V09).
        // A data da correção é o `created_at` — é ele que a tela lê ao dizer
        // "retificado em", e sem fixá-lo aqui o cenário anuncia a correção na
        // data em que o seeder foi executado.
        $retificacao = Atendimento::firstOrCreate(
            ['retifica_atendimento_id' => $atendimento->id],
            [
                ...$comum,
                'atendido_em' => '2025-11-12 16:20:00',
                'diagnostico' => 'Dermatite por Sarcoptes scabiei, confirmada pelo raspado cutâneo.',
                'motivo_retificacao' => 'Resultado do exame recebido três dias depois da consulta.',
            ],
        );

        $retificacao->forceFill(['created_at' => '2025-11-15 09:40:00'])->save();
    }

    /**
     * RF32 — os três anexos que T08 exibe. O laudo tem data de exame posterior
     * à consulta, e a foto das lesões não tem data própria: são os dois casos
     * que a lista precisa saber distinguir.
     */
    private function anexosDoAtendimento(Atendimento $atendimento): void
    {
        $arquivos = [
            [
                'descricao' => 'Raspado cutâneo',
                'exame_em' => '2025-11-12',
                'tipo' => 'imagem',
                'mime' => 'image/png',
                'conteudo' => ArquivoDeDemonstracao::png('Raspado cutâneo'),
                'extensao' => 'png',
            ],
            [
                'descricao' => 'Foto das lesões',
                'exame_em' => null,
                'tipo' => 'imagem',
                'mime' => 'image/png',
                'conteudo' => ArquivoDeDemonstracao::png('Foto das lesões'),
                'extensao' => 'png',
            ],
            [
                'descricao' => 'Laudo do raspado',
                'exame_em' => '2025-11-15',
                'tipo' => 'documento',
                'mime' => 'application/pdf',
                'conteudo' => ArquivoDeDemonstracao::pdf('Laudo do raspado cutâneo', [
                    'Animal: Théo · Clínica Vet Amigo',
                    'Coleta: 12/11/2025 · Resultado: 15/11/2025',
                    '',
                    'Achado: presença de Sarcoptes scabiei.',
                    '',
                    'Documento de demonstração do Imunia.',
                ]),
                'extensao' => 'pdf',
            ],
        ];

        foreach ($arquivos as $arquivo) {
            $caminho = "anexos/atendimento-{$atendimento->id}-".Str::slug($arquivo['descricao']).".{$arquivo['extensao']}";

            Storage::disk(AnexoAtendimento::DISCO)->put($caminho, $arquivo['conteudo']);

            AnexoAtendimento::firstOrCreate(
                ['atendimento_id' => $atendimento->id, 'descricao' => $arquivo['descricao']],
                [
                    'exame_em' => $arquivo['exame_em'],
                    'tipo' => $arquivo['tipo'],
                    'mime' => $arquivo['mime'],
                    'tamanho_bytes' => strlen($arquivo['conteudo']),
                    'caminho' => $caminho,
                ],
            );
        }
    }

    /**
     * O histórico exato do cenário do Claude Design (T05): três doses de V10
     * aplicadas na Clínica Vet Amigo — série primária completa, reforço
     * previsto para 26/12/2026, longe o bastante para ficar "em dia" — e a
     * antirrábica com um registro pregresso de 2024 (RN24) seguido da
     * aplicação profissional de 23/06/2025, cujo reforço anual já venceu.
     */
    private function historicoVacinalDeTheo(Animal $theo, Prestador $clinica): void
    {
        $v10 = Imunobiologico::where('chave', 'v10-multipla-canina')->firstOrFail();
        $antirrabica = Imunobiologico::where('chave', 'antirrabica')->firstOrFail();
        $protocoloV10 = $v10->protocoloVigente();
        $protocoloAntirrabica = $antirrabica->protocoloVigente();

        $doseComum = [
            'animal_id' => $theo->id,
            'prestador_id' => $clinica->id,
            'origem' => 'profissional',
            'aplicador_nome' => 'Dr. Marcelo Andrade',
            'aplicador_crmv' => 'CRMV-MG 12345',

            // RN27 — quem aplicou, e não só o nome que ficou no registro. É
            // esta coluna que V09 consulta para saber quem pode retificar; sem
            // ela o cenário atribui a aplicação a um profissional que o sistema
            // não sabe identificar, e nenhuma dose aparece corrigível.
            'aplicador_user_id' => $this->veterinario($clinica)->id,

            'via_administracao' => 'Subcutânea',
            'data_aproximada' => false,
        ];

        foreach ([
            ['data' => '2025-11-15', 'ordem' => 1, 'lote' => 'A2F-4469', 'validade' => '2027-09-30'],
            ['data' => '2025-12-06', 'ordem' => 2, 'lote' => 'A2F-4470', 'validade' => '2027-09-30'],
            ['data' => '2025-12-26', 'ordem' => 3, 'lote' => 'A2F-4471', 'validade' => '2027-09-30'],
        ] as $dose) {
            Vacinacao::firstOrCreate(
                ['animal_id' => $theo->id, 'imunobiologico_id' => $v10->id, 'ordem_dose' => $dose['ordem']],
                [
                    ...$doseComum,
                    'imunobiologico_id' => $v10->id,
                    'protocolo_vacinal_id' => $protocoloV10->id,
                    'fabricante' => 'Zoetis',
                    'lote' => $dose['lote'],
                    'validade' => $dose['validade'],
                    'aplicado_em' => $dose['data'],
                    'ordem_dose' => $dose['ordem'],
                ],
            );
        }

        // RF29 — lançado pela própria Helena, ano conhecido, dia não.
        $vacinacaoPregressa = Vacinacao::firstOrCreate(
            ['animal_id' => $theo->id, 'imunobiologico_id' => $antirrabica->id, 'origem' => 'pregresso'],
            [
                'animal_id' => $theo->id,
                'imunobiologico_id' => $antirrabica->id,
                'origem' => 'pregresso',
                'data_aproximada' => true,
                'aplicado_em' => '2024-06-01',
                'lancado_por_user_id' => $theo->tutor->user_id,
            ],
        );
        $vacinacaoPregressa->forceFill(['created_at' => '2026-01-12'])->save();

        Vacinacao::firstOrCreate(
            ['animal_id' => $theo->id, 'imunobiologico_id' => $antirrabica->id, 'origem' => 'profissional'],
            [
                ...$doseComum,
                'imunobiologico_id' => $antirrabica->id,
                'protocolo_vacinal_id' => $protocoloAntirrabica->id,
                'fabricante' => 'Zoetis',
                'lote' => 'R71-9020',
                'validade' => '2027-04-30',
                'aplicado_em' => '2025-06-23',
                'ordem_dose' => 1,
            ],
        );
    }
}
