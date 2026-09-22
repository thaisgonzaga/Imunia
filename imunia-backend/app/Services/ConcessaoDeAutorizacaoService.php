<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Autorizacao;
use App\Models\ConfirmacaoDeAutorizacao;
use App\Models\Prestador;
use App\Models\Tutor;
use App\Models\User;
use App\Notifications\AutorizacaoBloqueadaPorTentativas;
use App\Notifications\CodigoDeAutorizacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A concessão de autorização (T11, RF36 e RF37) — o ato central do modelo de
 * consentimento do sistema.
 *
 * Três coisas governam esta classe, e todas são subtrações:
 *
 * 1. A autorização é nominal e por animal (RN37). O tutor que marca dois
 *    animais não concede uma autorização dupla: concede duas, cada uma com sua
 *    linha, seu prazo e sua revogação. Não existe caminho aqui que produza uma
 *    linha sem animal determinado.
 * 2. Nada é gravado antes do código (RF37). O que a escolha do tutor produz é
 *    uma confirmação em aberto; a autorização só nasce depois que o código
 *    volta, e é isso que separa o ato de vontade da intenção manifestada no
 *    balcão da clínica.
 * 3. O prazo é de noventa dias (RN39), contados da confirmação e não da
 *    escolha. O tutor que abandona o fluxo por uma hora e volta recebe os
 *    noventa dias inteiros a partir do momento em que consentiu.
 */
class ConcessaoDeAutorizacaoService
{
    /**
     * Quantos estabelecimentos o passo 1 oferece sem que o tutor precise voltar
     * ao diretório. A lista não é o diretório (T10 é): são os que ele já
     * conhece, mais o que ele veio autorizar. Passando disto, o caminho é
     * "procurar outra clínica".
     */
    private const SUGESTOES = 8;

    public function __construct(private readonly SolicitacoesDeAcessoService $solicitacoes) {}

    /**
     * O que a tela precisa saber antes do primeiro passo: entre quais
     * estabelecimentos escolher, quais animais o tutor tem, e se o e-mail dele
     * está confirmado — porque sem endereço confirmado o fluxo não chega ao
     * fim (RF37b), e dizê-lo no começo é mais honesto do que no final.
     *
     * @return array<string, mixed>
     */
    public function opcoes(User $usuario, Tutor $tutor, ?int $prestadorEscolhido): array
    {
        $animais = $tutor->animais()->orderBy('nome')->get();
        $prestadores = $this->sugerirPrestadores($tutor, $prestadorEscolhido);
        $vigentes = $this->vigentesPorPrestador($tutor, $prestadores->pluck('id')->all());
        $bloqueio = ConfirmacaoDeAutorizacao::bloqueioDe($usuario);

        return [
            'tutor' => [
                // O endereço vem inteiro, e não mascarado como nas telas
                // públicas: aqui quem lê é o próprio titular, autenticado, e a
                // máscara só atrapalharia a conferência de para onde vai o
                // código.
                'email' => $usuario->email,
                'email_verificado' => $usuario->hasVerifiedEmail(),
            ],
            'prazo_em_dias' => Autorizacao::PRAZO_DIAS,
            'prestador_escolhido' => $prestadorEscolhido,
            'prestadores' => $prestadores
                ->map(fn (Prestador $prestador) => $this->cartaoDoPrestador(
                    $prestador,
                    $vigentes->get($prestador->id, new EloquentCollection),
                ))
                ->all(),
            'animais' => $animais->map(fn (Animal $animal) => $this->cartaoDoAnimal($animal))->all(),
            'bloqueio' => $bloqueio === null ? null : [
                'segundos_restantes' => $bloqueio->segundosDeBloqueio(),
            ],
        ];
    }

    /**
     * Abre a confirmação e manda o código. O que volta para a tela é o
     * expediente — prazo, tentativas, resumo do que será concedido —, jamais o
     * código (RF37c).
     *
     * @param  list<int>  $animais  ids já conferidos como do tutor
     */
    public function iniciar(User $usuario, Prestador $prestador, array $animais): ConfirmacaoDeAutorizacao
    {
        [$confirmacao, $codigo] = ConfirmacaoDeAutorizacao::abrir($usuario, $prestador, $animais);

        $usuario->notify(new CodigoDeAutorizacao(
            $codigo,
            $prestador->nome,
            $this->nomesDosAnimais($confirmacao),
        ));

        return $confirmacao;
    }

    /**
     * Emite código novo para a mesma escolha. A tela só oferece isto quando o
     * anterior venceu, mas quem decide continua sendo o servidor.
     */
    public function reenviar(ConfirmacaoDeAutorizacao $confirmacao): void
    {
        $codigo = $confirmacao->reenviar();

        $confirmacao->usuario->notify(new CodigoDeAutorizacao(
            $codigo,
            $confirmacao->prestador->nome,
            $this->nomesDosAnimais($confirmacao),
        ));
    }

    /**
     * O erro de digitação, contabilizado. Na última tentativa a pausa começa e
     * o tutor é avisado por e-mail — inclusive, e sobretudo, se não foi ele
     * quem digitou.
     */
    public function registrarCodigoErrado(ConfirmacaoDeAutorizacao $confirmacao): void
    {
        $confirmacao->registrarErro();

        if ($confirmacao->bloqueada()) {
            $confirmacao->usuario->notify(new AutorizacaoBloqueadaPorTentativas(
                $confirmacao->prestador->nome,
                $this->nomesDosAnimais($confirmacao),
                Carbon::now(),
            ));
        }
    }

    /**
     * O ato consumado: uma autorização por animal (RN37), todas na mesma
     * transação da baixa da confirmação — ou nasce tudo, ou nada, para que não
     * reste código gasto sem autorização nem autorização sem consentimento
     * registrado.
     *
     * A titularidade é reconferida aqui, e não só no início: entre a escolha e
     * o código o animal pode ter mudado de tutor (RN16), e a confirmação
     * guardada não é título de posse de coisa alguma.
     *
     * @return list<Autorizacao>
     */
    public function confirmar(ConfirmacaoDeAutorizacao $confirmacao, Tutor $tutor): array
    {
        return DB::transaction(function () use ($confirmacao, $tutor) {
            $animais = $this->animaisDaConfirmacao($confirmacao, $tutor);

            $concedidas = $animais->map(function (Animal $animal) use ($confirmacao) {
                $autorizacao = Autorizacao::create([
                    'animal_id' => $animal->id,
                    'prestador_id' => $confirmacao->prestador_id,

                    // RF36d — data, hora e origem do ato. A origem é o usuário
                    // que confirmou, e é dele que RF53 presta contas ao tutor.
                    'concedida_por_user_id' => $confirmacao->user_id,
                    'concedida_em' => Carbon::now(),

                    // RN39 — o prazo corre da confirmação, que é quando o
                    // consentimento existe.
                    'expira_em' => Carbon::now()->addDays(Autorizacao::PRAZO_DIAS),
                ]);

                // RF38 — o pedido que esta concessão atendeu deixa de esperar
                // resposta. A baixa é pelo par animal-prestador, e não pelo
                // pedido que a tela abriu: o tutor que chega pelo diretório, sem
                // passar por T13, respondeu ao pedido do mesmo jeito.
                $this->solicitacoes->atenderPendentes($animal, $confirmacao->prestador);

                // O animal já está em mãos: quem monta a resposta não precisa
                // ir buscá-lo de novo, um por linha criada.
                return $autorizacao->setRelation('animal', $animal);
            })->all();

            $confirmacao->forceFill(['confirmada_em' => Carbon::now()])->save();

            return $concedidas;
        });
    }

    /**
     * O resumo em texto corrido do passo 3 e da tela de êxito — o que o tutor
     * lê antes de digitar o código e o que ele revê depois de conceder.
     *
     * @return array<string, mixed>
     */
    public function resumo(ConfirmacaoDeAutorizacao $confirmacao, Tutor $tutor): array
    {
        $animais = $this->animaisDaConfirmacao($confirmacao, $tutor);

        return [
            'id' => $confirmacao->publico,
            'prestador' => [
                'id' => $confirmacao->prestador->id,
                'nome' => $confirmacao->prestador->nome,
                'tipo_rotulo' => $confirmacao->prestador->tipoRotulo(),
                'municipio' => $confirmacao->prestador->municipio,
                'uf' => $confirmacao->prestador->uf,
            ],
            'animais' => $animais
                ->map(fn (Animal $animal) => ['codigo' => $animal->codigo, 'nome' => $animal->nome])
                ->values()
                ->all(),
            'prazo_em_dias' => Autorizacao::PRAZO_DIAS,

            // A data que a frase de consentimento anuncia. Enquanto a
            // confirmação está aberta ela é previsão — o prazo só passa a
            // correr quando o código volta —, e é por isso que o cálculo é
            // feito agora, e não quando a confirmação foi criada.
            'expira_em' => Carbon::now()->addDays(Autorizacao::PRAZO_DIAS)->toDateString(),
            'email' => $confirmacao->usuario->email,
            'segundos_restantes' => $confirmacao->segundosRestantes(),
            'tentativas_restantes' => $confirmacao->tentativasRestantes(),
        ];
    }

    /**
     * Os animais da confirmação que ainda são do tutor, na ordem em que a tela
     * os mostrou.
     *
     * @return EloquentCollection<int, Animal>
     */
    public function animaisDaConfirmacao(ConfirmacaoDeAutorizacao $confirmacao, Tutor $tutor): EloquentCollection
    {
        /** @var list<int> $ids */
        $ids = $confirmacao->animais;

        return $tutor->animais()->whereIn('id', $ids)->orderBy('nome')->get();
    }

    /**
     * Os estabelecimentos que o passo 1 oferece: aquele de onde o tutor veio
     * (T10 manda o id na URL) e aqueles que ele já autorizou alguma vez —
     * vigentes, expirados ou revogados, porque a pergunta aqui é "com quem você
     * já tratou", e não "quem pode ver agora".
     *
     * @return EloquentCollection<int, Prestador>
     */
    private function sugerirPrestadores(Tutor $tutor, ?int $escolhido): EloquentCollection
    {
        $conhecidos = Autorizacao::query()
            ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
            ->orderByDesc('concedida_em')
            ->pluck('prestador_id')
            ->unique()
            ->take(self::SUGESTOES)
            ->all();

        $ids = array_values(array_unique(array_filter([$escolhido, ...$conhecidos])));

        if ($ids === []) {
            return new EloquentCollection;
        }

        $prestadores = Prestador::query()->whereIn('id', $ids)->get()->keyBy('id');

        // A ordem é a da lista montada acima — o escolhido primeiro, os demais
        // do mais recente ao mais antigo —, e não a que o banco devolveu.
        return new EloquentCollection(
            array_values(array_filter(array_map(
                fn (int $id) => $prestadores->get($id),
                $ids,
            ))),
        );
    }

    /**
     * As autorizações vigentes do tutor em cada prestador sugerido. É o que faz
     * o passo 2 exibir "já autorizado até 12/11/2026" no animal que não precisa
     * ser marcado de novo — e o que impede a concessão em duplicata, que
     * deixaria duas linhas vigentes para o mesmo par.
     *
     * @param  list<int>  $prestadores
     * @return EloquentCollection<int, EloquentCollection<int, Autorizacao>>
     */
    private function vigentesPorPrestador(Tutor $tutor, array $prestadores): EloquentCollection
    {
        if ($prestadores === []) {
            return new EloquentCollection;
        }

        return Autorizacao::query()
            ->vigente()
            ->whereIn('prestador_id', $prestadores)
            ->whereHas('animal', fn (Builder $animal) => $animal->where('tutor_id', $tutor->id))
            ->with('animal:id,codigo,nome')
            ->get()
            ->groupBy('prestador_id');
    }

    /**
     * @param  EloquentCollection<int, Autorizacao>  $vigentes
     * @return array<string, mixed>
     */
    private function cartaoDoPrestador(Prestador $prestador, EloquentCollection $vigentes): array
    {
        return [
            'id' => $prestador->id,

            // RF11a vale aqui como vale no diretório: identificação e
            // localização, nada de operação.
            'nome' => $prestador->nome,
            'tipo' => $prestador->tipo,
            'tipo_rotulo' => $prestador->tipoRotulo(),
            'municipio' => $prestador->municipio,
            'uf' => $prestador->uf,

            'autorizados' => $vigentes
                ->filter(fn (Autorizacao $autorizacao) => $autorizacao->animal !== null)
                ->map(fn (Autorizacao $autorizacao) => [
                    'codigo' => $autorizacao->animal->codigo,
                    'expira_em' => $autorizacao->expira_em->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * O cartão do passo 2. Deliberadamente mais enxuto que `paraListagem()`:
     * situação vacinal não é assunto desta tela, e trazê-la custaria uma
     * consulta de calendário por animal para desenhar uma etiqueta que o
     * desenho não tem.
     *
     * @return array<string, mixed>
     */
    private function cartaoDoAnimal(Animal $animal): array
    {
        return [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,
            'idade_em_meses' => $animal->idadeEmMeses(),
            'nascimento_exato' => $animal->nascimento_exato, // RN14
            'foto_url' => $animal->fotoUrl(),
        ];
    }

    /**
     * @return list<string>
     */
    private function nomesDosAnimais(ConfirmacaoDeAutorizacao $confirmacao): array
    {
        /** @var list<int> $ids */
        $ids = $confirmacao->animais;

        return Animal::query()->whereIn('id', $ids)->orderBy('nome')->pluck('nome')->all();
    }
}
