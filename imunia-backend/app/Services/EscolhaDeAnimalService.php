<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Vacinacao;
use App\Support\TermoDeBusca;
use Illuminate\Support\Collection;

/**
 * V07a e V08a — escolher o animal antes de registrar.
 *
 * A etapa existe por uma assimetria do fluxo clínico: acionado pela ficha
 * (V06), o registro já sabe de quem é; acionado pelo botão "Registrar" do
 * cabeçalho, falta o animal. Resolver essa falta com um campo dentro do
 * formulário de V07 abriria caminho para começar a redigir um registro de
 * animal fora do âmbito de autorização e descobrir o impedimento só ao
 * confirmar — depois de escrito o prontuário.
 *
 * Daí esta classe não ter busca própria: quem procura é `BuscaClinicaService`,
 * o mesmo de V03, com o mesmo âmbito (RN48), a mesma parcimônia sobre o que
 * existe fora dele (RN12) e o mesmo registro de acesso (RF18b). Uma segunda
 * busca com regra própria seria uma segunda chance de errar a única regra do
 * sistema que não admite erro.
 *
 * O que é desta tela é o atalho: quem passou pelo balcão nos últimos dias, com
 * a pendência vacinal à vista — porque a pergunta "qual animal você vai
 * vacinar?" quase sempre tem por resposta um dos animais do dia.
 */
class EscolhaDeAnimalService
{
    /**
     * Janela do atalho. É o intervalo padrão do painel (V01): "recentemente"
     * precisa significar a mesma coisa nas duas telas do mesmo profissional.
     */
    private const DIAS_DE_RECENCIA = PainelVeterinarioService::INTERVALO_PADRAO_DIAS;

    /**
     * Linhas do atalho. Atalho longo deixa de ser atalho: quem não está entre
     * os últimos atendidos é encontrado pela busca, que é o campo logo acima.
     */
    private const RECENTES = 5;

    public function __construct(
        private readonly BuscaClinicaService $busca,
        private readonly CalendarioVacinalService $calendario,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function montar(User $profissional, Prestador $prestador, TermoDeBusca $termo): array
    {
        return [
            ...$this->busca->buscar($profissional, $prestador, $termo),
            'recentes' => $this->recentes($prestador),
        ];
    }

    /**
     * Os animais atendidos pelo prestador ativo na janela, do mais recente para
     * o mais antigo — e só os que continuam sob autorização vigente. A
     * autorização pode ter caído ou sido revogada depois do atendimento (RF39,
     * RN39), e o atalho não pode oferecer como caminho de registro um animal
     * cujo histórico o prestador já não pode nem abrir.
     *
     * @return list<array<string, mixed>>
     */
    private function recentes(Prestador $prestador): array
    {
        $ultimos = $this->ultimoRegistroPorAnimal($prestador);

        if ($ultimos->isEmpty()) {
            return [];
        }

        return Animal::query()
            ->sobAutorizacaoVigenteDe($prestador)
            ->whereIn('id', $ultimos->keys())
            ->with('tutor')
            ->get()
            ->sortByDesc(fn (Animal $animal) => $ultimos[$animal->id])
            ->take(self::RECENTES)
            ->map(fn (Animal $animal) => $this->linha($animal))
            ->values()
            ->all();
    }

    /**
     * A data do último registro de cada animal no prestador, vinda das duas
     * origens que contam como passagem pelo balcão: o atendimento (RF31) e a
     * aplicação de vacina (RF25). Duas consultas agregadas, e não uma por
     * animal — a mesma forma que o painel usa para a lista de V01.
     *
     * @return Collection<int, string>
     */
    private function ultimoRegistroPorAnimal(Prestador $prestador): Collection
    {
        $desde = now()->subDays(self::DIAS_DE_RECENCIA);

        $porAtendimento = Atendimento::where('prestador_id', $prestador->id)
            ->where('atendido_em', '>=', $desde)
            ->selectRaw('animal_id, MAX(atendido_em) as em')
            ->groupBy('animal_id')
            ->pluck('em', 'animal_id');

        // Vacinação de origem `pregresso` é o que o tutor lançou de memória
        // (RF29): não houve passagem por este prestador, e incluí-la poria no
        // atalho do dia um animal que ninguém atendeu.
        $porVacina = Vacinacao::where('prestador_id', $prestador->id)
            ->where('origem', 'profissional')
            ->where('aplicado_em', '>=', $desde)
            ->selectRaw('animal_id, MAX(aplicado_em) as em')
            ->groupBy('animal_id')
            ->pluck('em', 'animal_id');

        return $porAtendimento->keys()
            ->merge($porVacina->keys())
            ->unique()
            ->mapWithKeys(fn (int $id) => [
                $id => max($porAtendimento[$id] ?? '', $porVacina[$id] ?? ''),
            ]);
    }

    /**
     * A linha do atalho. Traz o que decide a escolha no balcão — quem é o
     * animal, de quem é, e o que está pendente nele —, e nada do que só a ficha
     * responde: para isso existe V06, e o caminho até ela continua sendo o
     * cartão da busca.
     *
     * @return array<string, mixed>
     */
    private function linha(Animal $animal): array
    {
        $carteira = $this->calendario->montarCarteira($animal);

        // A dose mais urgente entre as que pedem alguma coisa. `proximas_doses`
        // já vem ordenada por data prevista, de modo que a primeira atrasada ou
        // próxima é também a mais antiga — a que o profissional teria procurado
        // na carteira antes de decidir o que aplicar.
        $pendente = collect($carteira['proximas_doses'])
            ->first(fn (array $dose) => in_array($dose['situacao']['tipo'], ['atrasada', 'proxima'], true));

        return [
            'codigo' => $animal->codigo,
            'nome' => $animal->nome,
            'especie' => $animal->especie,
            'idade_em_meses' => $animal->idadeEmMeses(),
            'nascimento_exato' => $animal->nascimento_exato, // RN14
            'tutor' => $animal->tutor->nome,

            // Qual vacina está pendente, e não apenas que há pendência: a tela
            // é o momento em que se decide o que aplicar, e "antirrábica há 42
            // dias" responde a pergunta que "atrasada" apenas anuncia.
            'pendencia' => $pendente === null ? null : [
                'imunobiologico' => $pendente['imunobiologico'],
                'situacao' => $pendente['situacao'],
            ],

            // Sem pendência, a etiqueta é a da carteira inteira — nula enquanto
            // não houver vacinação registrada, porque RF50 manda dizer que o
            // sistema ainda não sabe, nunca "em dia" por omissão.
            'situacao' => $this->calendario->situacaoDaCarteira($carteira),
        ];
    }
}
