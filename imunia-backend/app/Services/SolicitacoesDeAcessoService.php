<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Prestador;
use App\Models\SolicitacaoAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\SolicitacaoDeAcessoRecebida;
use App\Notifications\SolicitacaoDeAcessoRecusada;
use App\Support\TermoDeBusca;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RF38 — solicitações de acesso, dos dois lados do pedido.
 *
 * Do lado do tutor (T13), a tela é uma lista simples, e não um agrupamento por
 * animal como T12, porque a pergunta é outra: lá o tutor administra o que já
 * concedeu e pensa por animal ("quem vê o Théo?"); aqui ele responde a pedidos,
 * e um pedido chega um a um, com data. A ordem cronológica é a ordem da
 * resposta.
 *
 * Do lado do prestador (V10), o único ato é `solicitar` — e ele não abre porta
 * alguma. Nenhum método desta classe concede acesso: autorizar é o fluxo de
 * T11, com código enviado ao e-mail (RF37), e as telas apenas encaminham para
 * lá com o prestador e o animal já resolvidos. O que se faz aqui é pedir e
 * recusar — e recusar não pede código, porque negar acesso nunca pode custar
 * mais do que concedê-lo.
 */
class SolicitacoesDeAcessoService
{
    /**
     * Os pedidos que dizem respeito aos animais do tutor.
     *
     * Os já atendidos ficam de fora: o pedido que virou autorização deixou de
     * ser pergunta e passou a ser acesso, e o lugar de acompanhá-lo é T12.
     * Mostrá-lo nas duas telas faria o tutor revogar em uma e continuar vendo o
     * pedido na outra, como se restasse algo a responder.
     *
     * @return array<string, mixed>
     */
    public function listar(Tutor $tutor): array
    {
        $solicitacoes = $this->doTutor($tutor)
            ->reject(fn (SolicitacaoAcesso $solicitacao) => $solicitacao->atendida_em !== null);

        return [
            'prazo_em_dias' => SolicitacaoAcesso::PRAZO_DIAS,
            'pendentes' => $solicitacoes
                ->filter(fn (SolicitacaoAcesso $solicitacao) => $solicitacao->estaPendente())
                ->count(),
            'solicitacoes' => $solicitacoes
                // Pendentes à frente, encerrados atrás, e dentro de cada bloco
                // o mais recente primeiro. É a ordem da pergunta: o que espera
                // resposta antes do que já não espera nada.
                ->sortBy(fn (SolicitacaoAcesso $solicitacao) => [
                    $solicitacao->estaPendente() ? 0 : 1,
                    -$solicitacao->solicitada_em->timestamp,
                ])
                ->map(fn (SolicitacaoAcesso $solicitacao) => $this->cartao($solicitacao))
                ->values()
                ->all(),
        ];
    }

    /**
     * Quantos pedidos esperam resposta. É só isto que a moldura do tutor
     * precisa saber para desenhar o contador da aba, e pedir a lista inteira
     * para contar um número seria trazer o histórico de pedidos em toda
     * navegação do ambiente.
     */
    public function contarPendentes(Tutor $tutor): int
    {
        return SolicitacaoAcesso::query()
            ->pendente()
            ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
            ->count();
    }

    /**
     * V10 — o pedido (RF38). O alvo chega como termo, nunca como id: a busca de
     * V03 não entrega identificador de cadastro alheio (RN12), e o pedido
     * trabalha com o mesmo dado que o profissional já tinha — o CPF que o tutor
     * ditou, o código que veio no papel.
     *
     * Por CPF, o pedido se desdobra em uma linha por animal do tutor: a tabela
     * é nominal por animal (RN37) e o prestador não conhece a relação deles —
     * quem escolhe a quais responder é o tutor, um a um, em T13. A resposta ao
     * prestador é a mesma haja um animal, cinco ou nenhum: dizer "nada foi
     * criado porque este tutor não tem animais" seria a contagem que RN12
     * proíbe.
     *
     * @return array<string, mixed>
     */
    public function solicitar(
        User $profissional,
        Prestador $prestador,
        TermoDeBusca $termo,
        ?string $mensagem,
    ): array {
        [$tutor, $animais] = $this->alvosDoPedido($termo);

        $vigentes = Animal::query()
            ->sobAutorizacaoVigenteDe($prestador)
            ->whereIn('id', $animais->pluck('id'))
            ->pluck('id');

        $pendentes = SolicitacaoAcesso::query()
            ->pendente()
            ->where('prestador_id', $prestador->id)
            ->whereIn('animal_id', $animais->pluck('id'))
            ->orderByDesc('solicitada_em')
            ->get();

        $aCriar = $animais
            ->reject(fn (Animal $animal) => $vigentes->contains($animal->id))
            ->reject(fn (Animal $animal) => $pendentes->contains('animal_id', $animal->id));

        // Pela chave de animal o alvo é um só, e repetir o pedido é estado da
        // tela, não erro de quem tocou o botão de novo (V10: "já existe
        // solicitação pendente, com data"). Por CPF, o mesmo vale quando nenhum
        // animal restou a pedir — do contrário o pedido segue para os que
        // faltam.
        if ($aCriar->isEmpty() && $pendentes->isNotEmpty()) {
            return $this->jaPendente($pendentes->first());
        }

        if ($aCriar->isEmpty() && $termo->tipo !== TermoDeBusca::CPF && $vigentes->isNotEmpty()) {
            return [
                'situacao' => 'ja_autorizada',
                'message' => 'Já há autorização vigente para este animal: não há o que pedir. Abra a ficha dele pela busca.',
            ];
        }

        $expiraEm = Carbon::now()->addDays(SolicitacaoAcesso::PRAZO_DIAS);

        DB::transaction(function () use ($aCriar, $prestador, $profissional, $mensagem, $expiraEm): void {
            foreach ($aCriar as $animal) {
                SolicitacaoAcesso::create([
                    'animal_id' => $animal->id,
                    'prestador_id' => $prestador->id,
                    'solicitada_por_user_id' => $profissional->id,
                    'mensagem' => $mensagem,
                    'solicitada_em' => Carbon::now(),
                    'expira_em' => $expiraEm,
                ]);
            }
        });

        $tutorAtivado = $tutor->user?->ativado_em !== null;

        // Fora da transação, como todo envio da casa: falha de entrega não
        // desfaz o pedido criado. Sem ativação não há e-mail — a comunicação
        // pendente do titular é o convite, e o pedido o espera em T13.
        if ($tutorAtivado && $aCriar->isNotEmpty()) {
            $tutor->user->notify(new SolicitacaoDeAcessoRecebida(
                $prestador->nome,
                $aCriar->pluck('nome')->values()->all(),
                $mensagem,
            ));
        }

        return [
            'situacao' => 'enviada',
            'prazo_em_dias' => SolicitacaoAcesso::PRAZO_DIAS,
            'expira_em' => $expiraEm->toDateString(),

            // V10 — o estado "tutor não ativado": o pedido fica registrado, mas
            // a tela precisa dizer que a resposta depende de o titular ativar o
            // acesso primeiro. Para o cadastro feito no próprio balcão (V04),
            // este é o caminho comum, não a exceção.
            'tutor_ativado' => $tutorAtivado,

            'message' => 'Solicitação enviada. Nada muda até o tutor autorizar — e recusar também é uma resposta.',
        ];
    }

    /**
     * O estado de pendência que V03 e V06 exibem no lugar do botão: o pedido
     * deste prestador já existe e espera resposta. Só o próprio ato do
     * prestador é devolvido — nada do tutor viaja junto.
     *
     * @return array<string, mixed>|null
     */
    public function pendenciaParaAnimal(Prestador $prestador, Animal $animal): ?array
    {
        return $this->apresentarPendencia(
            SolicitacaoAcesso::query()
                ->pendente()
                ->where('prestador_id', $prestador->id)
                ->where('animal_id', $animal->id)
                ->latest('solicitada_em')
                ->first(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendenciaParaTutor(Prestador $prestador, Tutor $tutor): ?array
    {
        return $this->apresentarPendencia(
            SolicitacaoAcesso::query()
                ->pendente()
                ->where('prestador_id', $prestador->id)
                ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
                ->latest('solicitada_em')
                ->first(),
        );
    }

    /**
     * A recusa. Sem justificativa e sem campo para ela: o consentimento é
     * faculdade do titular, e cobrar explicação de quem nega seria transformar
     * a faculdade em ônus.
     *
     * O prestador é avisado do desfecho, e apenas dele — que o pedido não foi
     * aceito. Um motivo que não existe não viaja, e inventar um convidaria a
     * clínica a cobrá-lo do tutor no balcão.
     */
    public function recusar(SolicitacaoAcesso $solicitacao): SolicitacaoAcesso
    {
        $solicitacao->forceFill(['recusada_em' => Carbon::now()])->save();

        $aviso = new SolicitacaoDeAcessoRecusada(
            $solicitacao->prestador->nome,
            $solicitacao->animal->nome,
        );

        // Só quem atende — mesma razão de `MinhasAutorizacoesService`: o aviso
        // nomeia o animal, e nem o administrador (RN08) nem o profissional com
        // vínculo encerrado (RF10a) têm o que fazer com ele.
        foreach ($solicitacao->prestador->veterinariosAtivos as $usuario) {
            $usuario->notify($aviso);
        }

        return $solicitacao;
    }

    /**
     * Dá baixa nos pedidos que uma concessão atendeu. Chamado de T11, e não
     * daqui: quem responde "sim" a um pedido é o fluxo que concede, e o pedido
     * não pode continuar pendente depois que o acesso já existe.
     *
     * A baixa é pelo par animal-prestador, e não pelo pedido que a tela abriu:
     * o tutor que autoriza a clínica pelo diretório, sem passar por T13,
     * responde ao pedido do mesmo jeito — e deixá-lo pendente faria a tela
     * cobrar dele uma resposta que ele já deu.
     */
    public function atenderPendentes(Animal $animal, Prestador $prestador): void
    {
        SolicitacaoAcesso::query()
            ->pendente()
            ->where('animal_id', $animal->id)
            ->where('prestador_id', $prestador->id)
            ->update(['atendida_em' => Carbon::now()]);
    }

    /**
     * A quem o termo se refere. Por CPF, o tutor e todos os seus animais; pelas
     * chaves de animal, o animal único e o seu titular. Termo sem cadastro é
     * erro de campo, com a mesma frase da busca: quem chega aqui veio de um
     * cartão que afirmou existência, e cair nesse ramo é a janela entre a
     * consulta e o toque.
     *
     * @return array{0: Tutor, 1: EloquentCollection<int, Animal>}
     */
    private function alvosDoPedido(TermoDeBusca $termo): array
    {
        if ($termo->tipo === TermoDeBusca::CPF) {
            $tutor = Tutor::query()->where('cpf', $termo->valor)->first();

            if ($tutor === null) {
                throw ValidationException::withMessages([
                    'termo' => 'Nenhum cadastro corresponde a este CPF. Confira o número com o tutor.',
                ]);
            }

            // Em ordem alfabética, porque ela chega ao e-mail do tutor como
            // enumeração ("Nina e Théo") e uma ordem qualquer pareceria
            // escolha.
            return [$tutor, $tutor->animais()->orderBy('nome')->get()];
        }

        $coluna = $termo->tipo === TermoDeBusca::CODIGO ? 'codigo' : 'microchip';
        $animal = Animal::query()->where($coluna, $termo->valor)->first();

        if ($animal === null) {
            throw ValidationException::withMessages([
                'termo' => 'Nenhum cadastro corresponde a este termo. Confira os caracteres com o tutor.',
            ]);
        }

        return [$animal->tutor, new EloquentCollection([$animal])];
    }

    /**
     * @return array<string, mixed>
     */
    private function jaPendente(SolicitacaoAcesso $pendente): array
    {
        return [
            'situacao' => 'ja_pendente',
            ...$this->apresentarPendencia($pendente),
            'message' => sprintf(
                'Já existe uma solicitação pendente para este cadastro, feita em %s. O tutor ainda pode respondê-la.',
                $pendente->solicitada_em->format('d/m/Y'),
            ),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function apresentarPendencia(?SolicitacaoAcesso $solicitacao): ?array
    {
        if ($solicitacao === null) {
            return null;
        }

        return [
            'solicitada_em' => $solicitacao->solicitada_em->toDateString(),
            'expira_em' => $solicitacao->expira_em->toDateString(),
            'dias_restantes' => $solicitacao->diasRestantes(),
        ];
    }

    /**
     * @return EloquentCollection<int, SolicitacaoAcesso>
     */
    private function doTutor(Tutor $tutor): EloquentCollection
    {
        return SolicitacaoAcesso::query()
            ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
            ->with(['animal', 'prestador'])
            ->orderByDesc('solicitada_em')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function cartao(SolicitacaoAcesso $solicitacao): array
    {
        return [
            'id' => $solicitacao->id,
            'situacao' => $solicitacao->situacao(),
            'prestador' => $this->cartaoDoPrestador($solicitacao->prestador),
            'animal' => $this->cartaoDoAnimal($solicitacao->animal),

            // V10 — a linha de contexto que o prestador anexou ao pedir. Vai ao
            // cartão identificada como dele: é o que ajuda o tutor a reconhecer
            // de onde o pedido veio.
            'mensagem' => $solicitacao->mensagem,

            'solicitada_em' => $solicitacao->solicitada_em->toDateString(),
            'expira_em' => $solicitacao->expira_em->toDateString(),
            'recusada_em' => $solicitacao->recusada_em?->toDateString(),
            'dias_restantes' => $solicitacao->diasRestantes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartaoDoPrestador(Prestador $prestador): array
    {
        return [
            // RF11a — identificação e localização públicas. O pedido não é
            // ocasião para dizer do prestador mais do que o diretório diz.
            'id' => $prestador->id,
            'nome' => $prestador->nome,
            'tipo_rotulo' => $prestador->tipoRotulo(),
            'municipio' => $prestador->municipio,
            'uf' => $prestador->uf,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cartaoDoAnimal(Animal $animal): array
    {
        return [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,
            'foto_url' => $animal->fotoUrl(),
        ];
    }
}
