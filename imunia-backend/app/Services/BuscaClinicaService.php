<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Support\TermoDeBusca;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A busca do ambiente clínico (V03, RF51) — e, com ela, a regra que o briefing
 * chama de "a tela de maior risco de vazamento por desenho de interface de todo
 * o sistema".
 *
 * A resposta tem duas seções, e a diferença entre elas é a razão de ser desta
 * classe. A primeira é o que o prestador pode ver: animais sob autorização
 * vigente (RN48), com tudo. A segunda é o que ele não pode: a existência de um
 * cadastro, e nada além dela — sem nome de tutor, sem contato, sem relação de
 * animais, sem contagem (RN12, RF13a, RF13b, RF18a).
 *
 * A tentação natural, ao encontrar o cadastro, é exibir os dados para confirmar
 * que se trata da mesma pessoa. Fazê-lo converteria o CPF em chave de consulta
 * a dados pessoais de terceiros, que é exatamente a falha que o sistema inteiro
 * foi desenhado para não ter.
 */
class BuscaClinicaService
{
    /**
     * A busca serve ao balcão: quem procura um animal determinado o reconhece
     * nas primeiras linhas, e uma lista maior do que isto é sinal de que o
     * termo precisa ser mais específico, não de que faltam resultados.
     */
    private const RESULTADOS_POR_SECAO = 24;

    /**
     * Teto de tutores alcançados por um registro de busca por nome. A segunda
     * seção mostra um cartão só, mas o log de RF18b presta contas a cada
     * pessoa cuja existência foi revelada — e um termo genérico não pode virar
     * varredura da base inteira.
     */
    private const TUTORES_POR_REGISTRO = 25;

    public function __construct(
        private readonly CalendarioVacinalService $calendario,
        private readonly SolicitacoesDeAcessoService $solicitacoes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buscar(User $profissional, Prestador $prestador, TermoDeBusca $termo): array
    {
        $autorizados = $this->autorizados($prestador, $termo);
        $existencia = $this->existenciaForaDoAmbito($prestador, $termo, $autorizados);

        // RF52b — a gravação do log é condição da exibição, e não posterior a
        // ela. Por isso o registro acontece aqui, antes de a resposta ser
        // montada: se a linha não puder ser gravada, a existência não é
        // revelada, e não o contrário.
        if ($existencia !== null) {
            $this->registrar($profissional, $prestador, $termo, $existencia);
        }

        return [
            'termo' => $termo->original,
            'tipo' => $termo->tipo,

            // Vocabulário de estado das telas do veterinário (V01, V02), aqui
            // com os dois que são próprios desta: a tela ainda não perguntou
            // nada, e nada corresponde ao que se perguntou.
            'estado' => match (true) {
                $termo->vazio() => 'inicial',
                $autorizados->isEmpty() && $existencia === null => 'sem_resultado',
                default => 'normal',
            },

            'autorizados' => $this->cartoes($autorizados),
            'existencia' => $existencia['cartao'] ?? null,
        ];
    }

    /**
     * RN48 — a primeira seção é o âmbito do prestador, e nada mais. O escopo do
     * modelo é o mesmo de V01 e V02: um lugar só onde a condição de vigência
     * pode ser esquecida, e não três.
     *
     * @return Collection<int, Animal>
     */
    private function autorizados(Prestador $prestador, TermoDeBusca $termo): Collection
    {
        // Termo vazio não é busca por tudo: é a tela recém-aberta, que só
        // precisa saber em que prestador está.
        if ($termo->vazio()) {
            return new Collection;
        }

        $consulta = Animal::query()
            ->sobAutorizacaoVigenteDe($prestador)
            ->with([
                'tutor',
                'vacinacoes' => fn ($vacinacoes) => $vacinacoes->orderBy('aplicado_em'),

                // Até quando o acesso vale. É informação do prestador sobre a
                // própria autorização — nada do tutor, nada de terceiro —, e o
                // que permite a V07a confirmar a escolha do animal dizendo o
                // prazo em que o registro pode ser feito.
                'autorizacoes' => fn ($autorizacoes) => $autorizacoes
                    ->where('prestador_id', $prestador->id)
                    ->vigente()
                    ->orderByDesc('expira_em'),
            ]);

        $this->restringirAoTermo($consulta, $termo);

        return $consulta->orderBy('nome')->limit(self::RESULTADOS_POR_SECAO)->get();
    }

    /**
     * @param  Builder<Animal>  $consulta
     */
    private function restringirAoTermo(Builder $consulta, TermoDeBusca $termo): void
    {
        match ($termo->tipo) {
            TermoDeBusca::CPF => $consulta->whereHas(
                'tutor',
                fn (Builder $tutor) => $tutor->where('cpf', $termo->valor),
            ),
            TermoDeBusca::CODIGO => $consulta->where('codigo', $termo->valor),
            TermoDeBusca::MICROCHIP => $consulta->where('microchip', $termo->valor),

            // Por nome, o profissional pode estar procurando o animal ou quem o
            // trouxe — no balcão, "a Helena" e "o Théo" identificam a mesma
            // ficha, e obrigar a escolher qual dos dois se digitou seria uma
            // pergunta sem resposta interessante.
            default => $consulta->where(function (Builder $ou) use ($termo): void {
                $ou->where('nome', 'like', $this->curinga($termo))
                    ->orWhereHas('tutor', fn (Builder $tutor) => $tutor->where('nome', 'like', $this->curinga($termo)));
            }),
        };
    }

    /**
     * A segunda seção. Devolve o cartão que a tela exibe e os titulares a quem
     * o log presta contas — nunca os dados de um nem de outro.
     *
     * @param  Collection<int, Animal>  $autorizados
     * @return array{cartao: array<string, mixed>, tutores: list<int>, animal_id: int|null}|null
     */
    private function existenciaForaDoAmbito(
        Prestador $prestador,
        TermoDeBusca $termo,
        Collection $autorizados,
    ): ?array {
        if ($termo->vazio() || ! $termo->podeRevelarExistencia()) {
            return null;
        }

        if ($termo->tipo === TermoDeBusca::CPF) {
            $tutor = Tutor::where('cpf', $termo->valor)->first();

            // Com algum animal deste tutor já sob autorização, o profissional o
            // conhece pelo próprio resultado da primeira seção: anunciar de
            // novo que "existe um cadastro" não acrescentaria informação, e
            // dizer que existem *outros* animais seria a contagem que RN12
            // proíbe.
            if ($tutor === null || $autorizados->isNotEmpty()) {
                return null;
            }

            return [
                // RF13a e RF13b — apenas a existência. Nome, contato e relação
                // de animais permanecem ocultos até a autorização. A pendência
                // é o ato do próprio prestador, e é o que a tela mostra no
                // lugar do botão de pedir de novo (V10).
                'cartao' => [
                    'tipo' => 'tutor',
                    'solicitacao_pendente' => $this->solicitacoes->pendenciaParaTutor($prestador, $tutor),
                ],
                'tutores' => [$tutor->id],
                'animal_id' => null,
            ];
        }

        if ($termo->chaveExata()) {
            $coluna = $termo->tipo === TermoDeBusca::CODIGO ? 'codigo' : 'microchip';
            $animal = Animal::where($coluna, $termo->valor)->first();

            if ($animal === null || $autorizados->isNotEmpty()) {
                return null;
            }

            return [
                // RF18a — espécie, nome e a indicação de que há histórico
                // mediante autorização. O nome do animal não identifica o
                // tutor, e é o que permite ao profissional confirmar, com quem
                // está à sua frente, que ditou o código certo.
                'cartao' => [
                    'tipo' => 'animal',
                    'nome' => $animal->nome,
                    'especie' => $animal->especie,
                    'solicitacao_pendente' => $this->solicitacoes->pendenciaParaAnimal($prestador, $animal),
                ],
                'tutores' => [$animal->tutor_id],
                'animal_id' => $animal->id,
            ];
        }

        $tutores = $this->tutoresForaDoAmbito($termo, $autorizados);

        if ($tutores === []) {
            return null;
        }

        return [
            // Um cartão só, seja qual for o número de correspondências: o
            // desenho não tem plural. Cartão por correspondência transformaria
            // a busca por nome em contador de quantas pessoas com aquele nome
            // existem na plataforma.
            'cartao' => ['tipo' => 'outro'],
            'tutores' => $tutores,
            'animal_id' => null,
        ];
    }

    /**
     * @param  Collection<int, Animal>  $autorizados
     * @return list<int>
     */
    private function tutoresForaDoAmbito(TermoDeBusca $termo, Collection $autorizados): array
    {
        $curinga = $this->curinga($termo);

        $porTutor = Tutor::where('nome', 'like', $curinga)
            ->limit(self::TUTORES_POR_REGISTRO)
            ->pluck('id');

        $porAnimal = Animal::where('nome', 'like', $curinga)
            ->limit(self::TUTORES_POR_REGISTRO)
            ->pluck('tutor_id');

        // Tutor que já apareceu na primeira seção sai daqui inteiro, mesmo que
        // tenha outro animal fora do âmbito: a alternativa seria informar que
        // ele tem mais animais do que os exibidos, que é a contagem de RN12.
        return $porTutor
            ->merge($porAnimal)
            ->unique()
            ->diff($autorizados->pluck('tutor_id'))
            ->take(self::TUTORES_POR_REGISTRO)
            ->values()
            ->all();
    }

    /**
     * `%` e `_` digitados pelo profissional são texto, e não curinga: sem o
     * escape, um termo de um caractere só percorreria a base inteira.
     */
    private function curinga(TermoDeBusca $termo): string
    {
        return '%'.addcslashes($termo->valor, '%_\\').'%';
    }

    /**
     * RF18b — a consulta sem autorização fica registrada, e o tutor a vê (T14).
     * Uma linha por titular cuja existência foi revelada: por CPF ou por
     * código, é sempre um; por nome, são aqueles a quem o cartão coletivo se
     * refere, ainda que a tela mostre um cartão só.
     *
     * @param  array{cartao: array<string, mixed>, tutores: list<int>, animal_id: int|null}  $existencia
     */
    private function registrar(
        User $profissional,
        Prestador $prestador,
        TermoDeBusca $termo,
        array $existencia,
    ): void {
        $agora = now();

        RegistroDeAcesso::insert(array_map(fn (int $tutorId) => [
            'prestador_id' => $prestador->id,
            'user_id' => $profissional->id,
            'tutor_id' => $tutorId,
            'animal_id' => $existencia['animal_id'],
            'natureza' => $termo->naturezaDoRegistro(),
            'ocorrido_em' => $agora,
        ], $existencia['tutores']));
    }

    /**
     * O cartão completo da primeira seção: o mesmo vocabulário do painel do
     * veterinário (V01), acrescido da idade e do nome de quem o trouxe — que
     * aqui podem ser exibidos, porque há autorização vigente.
     *
     * Montado campo a campo, e não por `Animal::paraListagem()`, porque aquele
     * caminho recalcula a carteira animal a animal (uma consulta por linha) e
     * as vacinações já vieram carregadas com a consulta.
     *
     * @param  Collection<int, Animal>  $animais
     * @return list<array<string, mixed>>
     */
    private function cartoes(Collection $animais): array
    {
        return $animais->map(fn (Animal $animal) => [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,
            'idade_em_meses' => $animal->idadeEmMeses(),
            'nascimento_exato' => $animal->nascimento_exato, // RN14
            'preliminar' => $animal->preliminar(), // RN17
            'tutor' => $animal->tutor->nome,
            'autorizado_ate' => $animal->autorizacoes->first()?->expira_em->toDateString(),
            'situacao' => $this->calendario->situacaoDaCarteira(
                $this->calendario->montarCarteiraCom($animal, $animal->vacinacoes),
            ),
        ])->all();
    }
}
