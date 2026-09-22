<?php

namespace App\Services;

use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\Vacinacao;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Relação de registros da clínica — o destino "Registros" da barra lateral
 * (§5.3 do briefing). É o livro de produção do prestador ativo: cada
 * atendimento prestado e cada vacina aplicada aqui, do mais recente ao mais
 * antigo, com a autoria que assinou cada um (RN22).
 *
 * O âmbito é o inverso do da relação de animais, e a inversão é a tela: lá
 * decide a autorização vigente (RN48 — quem a clínica pode *acompanhar*);
 * aqui decide a autoria (RN40 — o que a clínica *produziu e guarda*). O
 * registro do animal cuja autorização venceu ou foi revogada continua no
 * livro, porque a revogação não o alcança — retirá-lo da relação desmentiria
 * a frase que T12 exibe ao tutor antes de revogar (RF39d) e a obrigação de
 * guarda que a Resolução CFMV nº 1.321/2020 impõe ao prestador.
 *
 * A guarda lista; a leitura integral é outra coisa. Sem autorização vigente a
 * linha vem sem endereço, porque V09 monta a série da carteira e a situação do
 * animal com registros de todos os prestadores — conteúdo que RN48 fecha com a
 * revogação. A leitura do registro sob guarda, na versão que não vaza o que é
 * de terceiros, é fatia própria.
 */
class RegistrosDaClinicaService
{
    /**
     * Os dois tipos de registro clínico profissional do sistema. O pregresso
     * (RF29) não é um terceiro tipo deste livro: não passou por prestador.
     *
     * @var list<string>
     */
    public const TIPOS = ['atendimento', 'vacinacao'];

    /** Linhas por página da tabela, no formato "Exibindo 1–25 de 137" (§5.1). */
    private const LINHAS_POR_PAGINA = 25;

    /**
     * @param  array{tipo: ?string, profissional: ?int}  $filtros
     * @return array<string, mixed>
     */
    public function consultar(Prestador $prestador, array $filtros, int $pagina): array
    {
        $todas = $this->linhas($prestador);

        // O id que não assina registro algum neste livro volta ao padrão, como
        // o valor fora da lista nos demais filtros (RF48b) — e é este o passo
        // que impede a consulta de virar sonda: o filtro só aceita quem o
        // próprio livro anuncia nas opções.
        $filtros['profissional'] = $this->profissionalDoLivro($todas, $filtros['profissional']);

        $filtradas = $this->aplicarFiltros($todas, $filtros);

        $total = $filtradas->count();
        $paginas = max(1, (int) ceil($total / self::LINHAS_POR_PAGINA));
        $pagina = min(max(1, $pagina), $paginas);
        $deslocamento = ($pagina - 1) * self::LINHAS_POR_PAGINA;

        return [
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'estado' => $todas->isEmpty() ? 'sem_registros' : 'normal',
            'filtros' => $this->descreverFiltros($todas, $filtros),
            'total' => $total,
            'total_sem_filtros' => $todas->count(),

            // A chave de ordenação e o id do autor são andaime da consulta,
            // não informação da linha: o que a tela precisa do autor está em
            // `responsavel`, e a ordem já está na sequência dos itens.
            'itens' => $filtradas
                ->slice($deslocamento, self::LINHAS_POR_PAGINA)
                ->map(fn (array $linha) => Arr::except($linha, ['ordenacao', 'profissional_id']))
                ->values()
                ->all(),
            'paginacao' => [
                'pagina' => $pagina,
                'paginas' => $paginas,
                'total' => $total,
                'de' => $total === 0 ? 0 : $deslocamento + 1,
                'ate' => min($deslocamento + self::LINHAS_POR_PAGINA, $total),
            ],
        ];
    }

    /**
     * RN40 — a consulta que define o âmbito: `prestador_id` nos próprios
     * registros, e nenhuma junção com autorização. A ordem é a cronológica do
     * ato clínico, invertida: livro se folheia do que acabou de acontecer para
     * trás, e a retificação — que conserva a data do original (V09) — aparece
     * ao lado do registro que corrige, que é onde RF33b a quer visível.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function linhas(Prestador $prestador): Collection
    {
        $atendimentos = Atendimento::query()
            ->where('prestador_id', $prestador->id)
            ->with(['animal.tutor', 'retificacao'])
            ->get();

        // A origem `profissional` além do prestador é redundância deliberada:
        // o pregresso (RF29) não carrega prestador, mas a dupla condição deixa
        // escrito que o que o tutor lançou de memória jamais entra no livro de
        // uma clínica (RN25).
        $vacinacoes = Vacinacao::query()
            ->where('prestador_id', $prestador->id)
            ->where('origem', 'profissional')
            ->with(['animal.tutor', 'imunobiologico', 'retificacao'])
            ->get();

        $autorizados = $this->animaisSobAutorizacao(
            $prestador,
            $atendimentos->pluck('animal_id')->merge($vacinacoes->pluck('animal_id'))->unique(),
        );

        return $atendimentos
            ->map(fn (Atendimento $registro) => $this->linhaDoAtendimento($registro, $prestador, $autorizados))
            ->merge($vacinacoes->map(
                fn (Vacinacao $registro) => $this->linhaDaVacinacao($registro, $prestador, $autorizados),
            ))
            ->sortByDesc(fn (array $linha) => $linha['ordenacao'])
            ->values();
    }

    /**
     * @param  Collection<int, int>  $autorizados
     * @return array<string, mixed>
     */
    private function linhaDoAtendimento(Atendimento $registro, Prestador $prestador, Collection $autorizados): array
    {
        return [
            ...$this->linhaComum($registro, $prestador, $registro->atendido_em, $autorizados),
            'tipo' => 'atendimento',
            'titulo' => $registro->titulo,
            'dose' => null,
            'responsavel' => [
                'nome' => $registro->profissional_nome,
                'crmv' => $registro->profissional_crmv,
            ],
            'profissional_id' => $registro->profissional_user_id,
        ];
    }

    /**
     * @param  Collection<int, int>  $autorizados
     * @return array<string, mixed>
     */
    private function linhaDaVacinacao(Vacinacao $registro, Prestador $prestador, Collection $autorizados): array
    {
        return [
            ...$this->linhaComum($registro, $prestador, $registro->aplicado_em, $autorizados),
            'tipo' => 'vacinacao',
            'titulo' => $registro->imunobiologico?->nome_comercial ?? 'Vacina não identificada',

            // A ordem declarada no ato (RF27), e não o rótulo que a carteira
            // calcula: o rótulo depende da série inteira do animal, que tem
            // registros de outros prestadores — e este livro só fala do que é
            // deste.
            'dose' => $registro->ordem_dose,
            'responsavel' => [
                'nome' => $registro->aplicador_nome,
                'crmv' => $registro->aplicador_crmv,
            ],
            'profissional_id' => $registro->aplicador_user_id,
        ];
    }

    /**
     * @param  Collection<int, int>  $autorizados
     * @return array<string, mixed>
     */
    private function linhaComum(
        Atendimento|Vacinacao $registro,
        Prestador $prestador,
        CarbonInterface $em,
        Collection $autorizados,
    ): array {
        $animal = $registro->animal;
        $sobAutorizacao = $autorizados->contains($animal->id);

        return [
            'id' => $registro->id,
            'em' => $em->toDateString(),

            // RF33b — as duas pontas do encadeamento, cada uma como marca da
            // sua linha: `retifica` diz que esta linha é uma correção;
            // `retificado`, que a versão que vale desta é outra. Nada sai do
            // livro por ter sido corrigido (RN26) — a marca é o que diz qual
            // das duas linhas responde pela dose ou pela consulta.
            'retifica' => $registro->ehRetificacao(),
            'retificado' => $registro->retificacao !== null,

            'animal' => [
                'codigo' => $animal->codigo,
                'nome' => $animal->nome,
                'especie' => $animal->especie,
            ],
            'tutor' => $animal->tutor->nome,
            'sob_autorizacao' => $sobAutorizacao,

            // Sem autorização vigente, sem endereço: V09 responderia 403, e a
            // tela não oferece o que o servidor recusa (RNF09). A linha fica —
            // é a guarda de RN40 —, e o endereço volta com a autorização.
            'url' => $sobAutorizacao
                ? $this->caminho($registro, $animal, $prestador)
                : null,

            'ordenacao' => [
                $em->format('Y-m-d H:i:s'),
                $registro->created_at?->format('Y-m-d H:i:s.u') ?? '',
                $registro->id,
            ],
        ];
    }

    /**
     * O endereço de V09, com o prestador ativo no contexto — como nos destinos
     * que a própria retificação devolve: sem ele, o pedido recairia no
     * primeiro vínculo do profissional (RF09b).
     */
    private function caminho(Atendimento|Vacinacao $registro, Animal $animal, Prestador $prestador): string
    {
        $segmento = $registro instanceof Atendimento ? 'atendimentos' : 'vacinas';

        return "/clinica/animais/{$animal->codigo}/{$segmento}/{$registro->id}?prestador={$prestador->id}";
    }

    /**
     * RN48 — a única pergunta sobre autorização que este livro faz, e ela não
     * decide quem figura: decide qual linha tem endereço.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function animaisSobAutorizacao(Prestador $prestador, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        return Animal::query()
            ->whereIn('id', $ids)
            ->sobAutorizacaoVigenteDe($prestador)
            ->pluck('id');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @param  array{tipo: ?string, profissional: ?int}  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function aplicarFiltros(Collection $linhas, array $filtros): Collection
    {
        return $linhas
            ->when(
                $filtros['tipo'] !== null,
                fn (Collection $itens) => $itens->where('tipo', $filtros['tipo']),
            )
            ->when(
                $filtros['profissional'] !== null,
                fn (Collection $itens) => $itens->where('profissional_id', $filtros['profissional']),
            )
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $linhas
     */
    private function profissionalDoLivro(Collection $linhas, ?int $id): ?int
    {
        return $linhas->contains('profissional_id', $id) ? $id : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $todas
     * @param  array{tipo: ?string, profissional: ?int}  $filtros
     * @return array<string, mixed>
     */
    private function descreverFiltros(Collection $todas, array $filtros): array
    {
        return [
            ...$filtros,
            'opcoes' => [
                'tipos' => [
                    ['valor' => 'atendimento', 'rotulo' => 'Atendimento'],
                    ['valor' => 'vacinacao', 'rotulo' => 'Vacinação'],
                ],
                'profissionais' => $this->profissionaisDoLivro($todas),
            ],
        ];
    }

    /**
     * As opções do filtro de profissional saem do próprio livro, e não da
     * equipe de A03 — de propósito: quem teve o vínculo encerrado continua
     * assinando os registros que produziu (RF10b), e um filtro montado da
     * equipe ativa tornaria essas linhas inencontráveis. O nome é o retrato
     * mais recente, porque é assim que as linhas o exibem.
     *
     * @param  Collection<int, array<string, mixed>>  $linhas
     * @return list<array{valor: int, rotulo: string}>
     */
    private function profissionaisDoLivro(Collection $linhas): array
    {
        return $linhas
            ->filter(fn (array $linha) => $linha['profissional_id'] !== null)
            ->groupBy('profissional_id')
            ->map(fn (Collection $do, int $id) => [
                'valor' => $id,
                'rotulo' => $do->first()['responsavel']['nome'],
            ])
            ->sortBy(fn (array $opcao) => $opcao['rotulo'], SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
