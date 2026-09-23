<?php

namespace App\Services;

use App\Models\Convite;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A equipe de um prestador (A03, RF09, RF10): quem atende ali, em que situação,
 * e o que se pode fazer com cada vínculo.
 *
 * Serve A03, que é a tela da equipe, e também A01, que só precisa das
 * contagens — as duas leem a mesma verdade, e é por isso que a situação de um
 * membro é decidida em um lugar só. Situação derivada em duas telas divergiria
 * na primeira mudança de regra, e a divergência apareceria como um convite que
 * A01 conta como pendente e A03 mostra como ativo.
 */
class EquipeDoPrestador
{
    /**
     * A partir de quantos dias para a expiração o convite vira pendência de
     * configuração em A01. Três dias é o que dá para reenviar e a pessoa ainda
     * agir sem pressa, dentro dos sete de `Convite::VALIDADE_EM_DIAS`.
     */
    public const DIAS_PARA_AVISAR_EXPIRACAO = 3;

    public const ATIVO = 'ativo';

    public const CONVITE_PENDENTE = 'convite_pendente';

    public const CONVITE_EXPIRADO = 'convite_expirado';

    public const ENCERRADO = 'encerrado';

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @param  bool  $administra  se quem olha administra a conta — quem apenas
     *                            atende aqui vê a equipe, e nenhuma ação sobre ela
     */
    public function listar(Prestador $prestador, User $autenticado, bool $administra = true): array
    {
        $convites = $this->convitesEmAberto($prestador);
        $administradores = $this->administradoresAtivos($prestador);
        $concede = $administra && $autenticado->ehResponsavelTecnicoDe($prestador);

        $membros = $prestador->equipe()->get();

        // Convite pendente e vínculo encerrado são assunto de quem administra: o
        // primeiro é um endereço que ainda não virou colega, e o segundo está na
        // lista porque RF10 pede que a saída fique registrada. Quem só atende
        // aqui pergunta outra coisa — quem trabalha comigo hoje.
        if (! $administra) {
            $membros = $membros->filter(
                fn (User $membro) => $this->situacao($membro->pivot->encerrado_em, $convites->get($membro->id))
                    === self::ATIVO,
            );
        }

        return $membros->map(
            fn (User $membro) => $this->representar(
                $prestador,
                $membro,
                $convites->get($membro->id),
                $autenticado,
                $administradores,
                $concede,
                $administra,
            ),
        )->values()->all();
    }

    /**
     * Quem, nesta conta, pode conceder e retirar a administração — e o que
     * dizer a quem não pode.
     *
     * A escolha é de projeto e vale a pena declará-la na interface: administrar
     * a conta é poder convidar, desligar e alterar o cadastro do
     * estabelecimento, e quem responde tecnicamente por ele é quem decide a
     * quem esse poder cabe. Sem a frase, o administrador que não encontra a
     * ação procuraria defeito onde há regra (RNF09).
     *
     * @param  list<array<string, mixed>>  $membros
     * @return array{pode_conceder: bool, restricao: string|null}
     */
    public function concessao(Prestador $prestador, User $autenticado, array $membros, bool $administra = true): array
    {
        // Ser responsável técnico não basta: conceder é ato administrativo, e
        // quem não administra a conta não o pratica. Sem esta condição, o
        // responsável técnico que ainda não recebeu o papel via na tela um
        // botão que o servidor recusaria.
        if ($administra && $autenticado->ehResponsavelTecnicoDe($prestador)) {
            return ['pode_conceder' => true, 'restricao' => null];
        }

        return [
            'pode_conceder' => false,
            'restricao' => $this->restricaoDaConcessao($membros),
        ];
    }

    /**
     * Quem administra esta conta, pelo nome — a resposta à pergunta que a tela
     * de quem só atende aqui faz: a quem pedir.
     *
     * Sai daqui, e não da lista da equipe, porque nem todo administrador é
     * veterinário: a administradora sem CRMV não figura em `equipe()`, que é a
     * relação dos vínculos clínicos, e montar a frase a partir da tabela
     * deixaria de fora justamente quem cuida da conta.
     *
     * @return list<string>
     */
    public function administradores(Prestador $prestador): array
    {
        return $prestador->usuarios()
            ->wherePivot('papel', 'admin_prestador')
            ->wherePivotNull('encerrado_em')
            ->pluck('users.name')
            ->filter(fn (?string $nome) => filled($nome))
            ->values()
            ->all();
    }

    /**
     * RF09 — o papel administrativo passa a ser de mais um membro da equipe. É
     * linha nova no pivô, e não alteração da existente: quem administra e
     * atende tem dois vínculos com o mesmo prestador, um por papel, e é essa
     * separação que permite retirar a administração sem tocar no CRMV com que
     * a pessoa assina os registros.
     */
    public function conceder(Prestador $prestador, User $membro): void
    {
        if (in_array($membro->id, $this->administradoresAtivos($prestador), true)) {
            return;
        }

        $prestador->usuarios()->attach($membro->id, [
            'papel' => 'admin_prestador',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Retira a administração e deixa o vínculo clínico intacto: a pessoa
     * continua atendendo ali, e os registros que assinou continuam sendo dela.
     *
     * A linha é encerrada, e não removida, pelo mesmo motivo de `encerrar()` —
     * quem administrou a conta em algum momento faz parte da história dela.
     */
    public function revogar(Prestador $prestador, User $membro): void
    {
        DB::table('prestador_usuario')
            ->where('prestador_id', $prestador->id)
            ->where('user_id', $membro->id)
            ->where('papel', 'admin_prestador')
            ->whereNull('encerrado_em')
            ->update(['encerrado_em' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /**
     * A quem pedir a concessão — e o que fazer quando não há a quem pedir.
     *
     * O segundo caso é real e travaria a conta em silêncio: o cadastro nomeia
     * um responsável técnico por CRMV (RF07c), e nada garante que a pessoa
     * daquela inscrição tenha vínculo ativo aqui. Sem esta frase, a
     * administradora veria uma ação que ninguém pode executar e nenhuma pista
     * de que o caminho é o convite — ou a correção do cadastro.
     *
     * @param  list<array<string, mixed>>  $membros
     */
    private function restricaoDaConcessao(array $membros): string
    {
        // Ativo, e não apenas portador da inscrição: quem foi desligado
        // continua com o CRMV na linha do pivô, e anunciá-lo como responsável
        // técnico de hoje mandaria a administradora procurar quem já saiu.
        $responsavel = collect($membros)->first(
            fn (array $membro) => $membro['responsavel_tecnico'] && $membro['situacao'] === self::ATIVO,
        );

        if ($responsavel === null) {
            return 'Conceder e retirar a administração da conta é do responsável técnico, e nenhum '
                .'vínculo ativo da equipe traz o CRMV informado em Dados do prestador.';
        }

        return 'Conceder e retirar a administração da conta é do responsável técnico'
            .(blank($responsavel['nome']) ? '' : ' — hoje, '.$responsavel['nome']).'.';
    }

    /**
     * Convida um médico-veterinário (RF09). A conta nasce sem senha utilizável
     * e sem nome: quem define os dois é o próprio convidado, ao aceitar. Se já
     * existe conta com aquele endereço, ela é reaproveitada — RF09 admite
     * vínculo simultâneo com mais de um prestador, e criar uma segunda conta
     * para a mesma pessoa quebraria o histórico dela nos dois.
     *
     * @param  array{email: string, crmv: string, crmv_uf: string}  $dados
     */
    public function convidar(Prestador $prestador, User $convidante, array $dados): void
    {
        [$usuario, $token] = DB::transaction(function () use ($prestador, $convidante, $dados) {
            $usuario = User::query()->where('email', $dados['email'])->first()
                ?? User::create([
                    // Vazio, e não um chute a partir do endereço: o nome carimba
                    // cada registro clínico que esta pessoa vier a assinar, e
                    // quem o escreve é ela (RF09).
                    'name' => '',
                    'email' => $dados['email'],
                    // Inacessível de propósito — a senha real é definida no
                    // aceite. Sem isto a conta ficaria sem `password`, que é
                    // coluna obrigatória.
                    'password' => Str::random(64),
                ]);

            $prestador->usuarios()->attach($usuario->id, [
                'papel' => 'veterinario',
                'crmv' => $dados['crmv'],
                'crmv_uf' => $dados['crmv_uf'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            [$convite, $token] = Convite::emitir($usuario, $prestador, 'veterinario', $convidante);

            return [$convite->usuario, $token];
        });

        // Fora da transação, como em P03: o envio é efeito colateral, e uma
        // falha de entrega não pode desfazer o vínculo já criado.
        Convite::query()
            ->where('user_id', $usuario->id)
            ->where('prestador_id', $prestador->id)
            ->latest('id')
            ->first()
            ?->enviar($token);
    }

    /**
     * RF14a — renova o prazo e troca o token no mesmo ato: o convite antigo
     * deixa de valer quando o novo sai.
     */
    public function reenviar(Prestador $prestador, User $membro): void
    {
        $convite = Convite::query()
            ->where('prestador_id', $prestador->id)
            ->where('user_id', $membro->id)
            ->where('tipo', 'veterinario')
            ->whereNull('aceito_em')
            ->latest('id')
            ->first();

        abort_if($convite === null, 422, 'Este vínculo não tem convite pendente para reenviar.');

        $convite->enviar($convite->reemitir());
    }

    /**
     * RF10 — encerra o vínculo. A linha permanece: o que cessa é o acesso, e
     * quem responde tecnicamente pelos registros já produzidos continua sendo
     * quem os assinou (RF10b, RF10c).
     *
     * Encerra **todas** as linhas daquela pessoa naquele prestador, e não só a
     * de veterinário. Quem cadastrou a clínica em P04 tem duas, e encerrar uma
     * só deixaria a pessoa sem o ambiente clínico mas ainda administrando a
     * conta — o oposto de RF10a, que manda cessar o acesso ao prestador, não a
     * uma parte dele.
     */
    public function encerrar(Prestador $prestador, User $membro): void
    {
        DB::transaction(function () use ($prestador, $membro) {
            DB::table('prestador_usuario')
                ->where('prestador_id', $prestador->id)
                ->where('user_id', $membro->id)
                ->whereNull('encerrado_em')
                ->update(['encerrado_em' => Carbon::now(), 'updated_at' => Carbon::now()]);

            // Um convite ainda em aberto viraria um acesso que acabou de ser
            // retirado: quem tem a ligação em mãos poderia aceitá-la depois do
            // encerramento.
            Convite::query()
                ->where('prestador_id', $prestador->id)
                ->where('user_id', $membro->id)
                ->whereNull('aceito_em')
                ->update(['expira_em' => Carbon::now()]);
        });
    }

    /**
     * Resolve a linha de vínculo que a tela indicou, dentro do prestador
     * administrado. O escopo é o ponto: procurar o vínculo pelo id sem amarrá-lo
     * ao prestador deixaria um administrador encerrar equipe alheia.
     */
    public function membroDoVinculo(Prestador $prestador, int $vinculo): User
    {
        $membro = $prestador->usuarios()->wherePivot('id', $vinculo)->first();

        abort_if($membro === null, 404, 'Vínculo não encontrado.');

        return $membro;
    }

    /**
     * As três contagens do cartão de equipe de A01. Vínculo encerrado é contado
     * à parte, e não simplesmente omitido: a clínica que desligou três
     * profissionais no semestre tem uma história que o número de ativos sozinho
     * não conta.
     *
     * @param  list<array<string, mixed>>  $membros
     * @return array{ativos: int, convites_pendentes: int, encerrados: int}
     */
    public function contagens(array $membros): array
    {
        $situacoes = collect($membros)->countBy('situacao');

        return [
            'ativos' => $situacoes->get(self::ATIVO, 0),
            'convites_pendentes' => $situacoes->get(self::CONVITE_PENDENTE, 0)
                + $situacoes->get(self::CONVITE_EXPIRADO, 0),
            'encerrados' => $situacoes->get(self::ENCERRADO, 0),
        ];
    }

    /**
     * Os convites de equipe ainda não aceitos, indexados pelo usuário. Uma
     * consulta só para a tabela inteira: perguntar por membro transformaria a
     * lista da equipe em uma consulta por linha.
     *
     * @return Collection<int, Convite>
     */
    private function convitesEmAberto(Prestador $prestador): Collection
    {
        return Convite::query()
            ->where('prestador_id', $prestador->id)
            ->where('tipo', 'veterinario')
            ->whereNull('aceito_em')
            ->get()
            ->keyBy('user_id');
    }

    /**
     * @param  list<int>  $administradores  ids dos administradores ainda ativos
     * @return array<string, mixed>
     */
    private function representar(
        Prestador $prestador,
        User $membro,
        ?Convite $convite,
        User $autenticado,
        array $administradores,
        bool $concede,
        bool $administra,
    ): array {
        $encerradoEm = $membro->pivot->encerrado_em;
        $situacao = $this->situacao($encerradoEm, $convite);
        $responsavelTecnico = $this->ehResponsavelTecnico($prestador, $membro);
        // Sem poder de agir não há ação ausente a explicar: a tela de quem só
        // atende aqui diz numa frase de quem é a administração, e não repete o
        // motivo em cada linha de uma coluna que ela nem desenha.
        $impedimento = $administra
            ? $this->impedimento($membro, $autenticado, $situacao, $responsavelTecnico, $administradores)
            : null;
        $administrador = in_array($membro->id, $administradores, true);
        $impedimentoAdministracao = $this->impedimentoDaAdministracao(
            $membro,
            $autenticado,
            $situacao,
            $administrador,
        );

        return [
            'vinculo' => (int) $membro->pivot->id,
            // Enquanto o convite de uma conta nova não é aceito, não há nome a
            // exibir — quem o define é o próprio convidado, no primeiro acesso.
            // A tela mostra o endereço no lugar, e é honesto que mostre: é tudo
            // o que a clínica sabe sobre ele até aqui.
            //
            // O critério é a ausência do nome, e não a de `ativado_em`: quem já
            // tinha conta antes de ser convidado chega aqui com nome e sem
            // aceite, e esconder o nome dele seria esconder o que se sabe.
            'nome' => blank($membro->name) ? null : $membro->name,
            'email' => $membro->email,
            'crmv' => $this->crmvDoVinculo($membro),
            'situacao' => $situacao,
            'situacao_texto' => $this->situacaoTexto($situacao, $encerradoEm, $convite, $responsavelTecnico),
            'responsavel_tecnico' => $responsavelTecnico,
            // Vai junto do membro para que A01 monte a pendência de convite a
            // expirar sem reabrir a tabela de convites: a equipe já a leu.
            'dias_para_expirar' => $situacao === self::CONVITE_PENDENTE
                ? $this->diasAteExpirar($convite)
                : null,
            'pode_encerrar' => $administra && $situacao !== self::ENCERRADO && $impedimento === null,
            'pode_reenviar' => $administra
                && in_array($situacao, [self::CONVITE_PENDENTE, self::CONVITE_EXPIRADO], true),
            // A tela não deduz por que falta o botão: ela exibe o motivo. Ação
            // ausente sem explicação lê-se como defeito, e o administrador
            // procuraria o problema onde não está (RNF09).
            //
            // Duas formas do mesmo texto porque a tabela tem duas larguras: a
            // célula de 150 px do desenho de 1440 px não comporta a frase
            // inteira — ela quebraria em quatro linhas e levaria a linha de 48
            // para quase 100 px. O resumo vai na célula; a frase inteira, no
            // `title` e no cartão do celular, onde há largura para ela.
            'impedimento' => $impedimento,
            'impedimento_resumo' => $this->resumirImpedimento($impedimento),
            // Quem administra a conta, ao lado de quem atende: até aqui a tela
            // da equipe não dizia — o serviço já sabia, e a informação só
            // servia para barrar o encerramento do último administrador.
            'administrador' => $administrador,
            'pode_conceder_administracao' => $concede && ! $administrador && $impedimentoAdministracao === null,
            'pode_revogar_administracao' => $concede && $administrador && $impedimentoAdministracao === null,
            'impedimento_administracao' => $impedimentoAdministracao,
        ];
    }

    /**
     * Por que a administração desta linha não se mexe — ou `null` quando se
     * mexe. Fala só do membro; que o autenticado não seja o responsável
     * técnico é dito uma vez pela tela, e não em cada linha (ver `concessao()`).
     */
    private function impedimentoDaAdministracao(
        User $membro,
        User $autenticado,
        string $situacao,
        bool $administrador,
    ): ?string {
        // Convite pendente é endereço de correio, não pessoa: conceder
        // administração a quem ainda não entrou seria entregar a conta a quem
        // quer que abra a mensagem.
        if ($situacao !== self::ATIVO) {
            return $situacao === self::ENCERRADO
                ? 'O vínculo está encerrado.'
                : 'O convite ainda não foi aceito. A administração se concede a quem já entrou.';
        }

        // Retirar a própria administração é sair da tela pela porta que se está
        // usando. E como quem concede é sempre administrador, esta recusa já
        // cobre o último administrador da conta: a lista nunca se resume a
        // outra pessoa.
        if ($administrador && $membro->id === $autenticado->id) {
            return 'Você não pode retirar a própria administração.';
        }

        return null;
    }

    private function resumirImpedimento(?string $impedimento): ?string
    {
        return match (true) {
            $impedimento === null => null,
            str_starts_with($impedimento, 'Você') => 'Seu próprio vínculo',
            str_starts_with($impedimento, 'É o responsável') => 'Responsável técnico',
            default => 'Único administrador',
        };
    }

    /**
     * Por que este vínculo não pode ser encerrado — ou `null` quando pode.
     *
     * As três recusas protegem estados de que a conta não se recupera sozinha:
     * ficar sem responsável técnico (RF07c) impede registrar qualquer coisa;
     * ficar sem administrador tranca o cadastro; e encerrar o próprio vínculo
     * faria o administrador se desligar da conta que está administrando.
     *
     * @param  list<int>  $administradores
     */
    private function impedimento(
        User $membro,
        User $autenticado,
        string $situacao,
        bool $responsavelTecnico,
        array $administradores,
    ): ?string {
        if ($situacao === self::ENCERRADO) {
            return null;
        }

        if ($membro->id === $autenticado->id) {
            return 'Você não pode encerrar o próprio vínculo.';
        }

        if ($responsavelTecnico) {
            return 'É o responsável técnico do prestador. Informe outro em Dados do prestador antes de encerrar.';
        }

        if ($administradores === [$membro->id]) {
            return 'É o único administrador da conta.';
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function administradoresAtivos(Prestador $prestador): array
    {
        return $prestador->usuarios()
            ->wherePivot('papel', 'admin_prestador')
            ->wherePivotNull('encerrado_em')
            ->pluck('users.id')
            ->all();
    }

    private function situacao(mixed $encerradoEm, ?Convite $convite): string
    {
        if ($encerradoEm !== null) {
            return self::ENCERRADO;
        }

        if ($convite === null) {
            return self::ATIVO;
        }

        return $convite->expirou() ? self::CONVITE_EXPIRADO : self::CONVITE_PENDENTE;
    }

    /**
     * O texto da pílula de situação sai pronto do servidor, como o de
     * `StatusPill` em toda tela do sistema: a data já formatada e a contagem de
     * dias já resolvida, para que duas telas não cheguem a números diferentes a
     * partir do mesmo prazo.
     */
    private function situacaoTexto(
        string $situacao,
        mixed $encerradoEm,
        ?Convite $convite,
        bool $responsavelTecnico,
    ): string {
        return match ($situacao) {
            self::ENCERRADO => 'encerrado em '.Carbon::parse($encerradoEm)->format('d/m/Y'),
            self::CONVITE_EXPIRADO => 'convite expirado',
            self::CONVITE_PENDENTE => 'convite · expira em '.$this->diasAteExpirar($convite).' d',
            default => $responsavelTecnico ? 'ativo · resp. técnico' : 'ativo',
        };
    }

    public function diasAteExpirar(Convite $convite): int
    {
        return (int) max(1, ceil(Carbon::now()->floatDiffInDays($convite->expira_em, false)));
    }

    /**
     * O responsável técnico é reconhecido pelo CRMV, e não pelo nome: é o
     * número da inscrição que o vincula ao estabelecimento perante o conselho
     * (RN09), e é por ele que P04 amarra os dois no cadastro.
     */
    private function ehResponsavelTecnico(Prestador $prestador, User $membro): bool
    {
        if ($prestador->responsavel_tecnico_crmv === null) {
            return false;
        }

        return $membro->pivot->crmv === $prestador->responsavel_tecnico_crmv
            && $membro->pivot->crmv_uf === $prestador->responsavel_tecnico_crmv_uf;
    }

    private function crmvDoVinculo(User $membro): ?string
    {
        if ($membro->pivot->crmv === null) {
            return null;
        }

        return "CRMV-{$membro->pivot->crmv_uf} {$membro->pivot->crmv}";
    }
}
