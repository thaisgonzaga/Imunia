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
 * decide o vínculo (RN48 — quem a clínica *acompanha*); aqui decide a autoria
 * (o que a clínica *produziu e guarda*, como manda a Resolução CFMV
 * nº 1.321/2020). Toda linha tem endereço: o registro se abre pelo código do
 * animal, como qualquer outro.
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
     * A consulta que define o âmbito: `prestador_id` nos próprios
     * registros, e nenhuma junção com vínculo. A ordem é a cronológica do
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

        // `toBase()` antes de tudo: sem atendimentos, o `map` da coleção
        // Eloquent devolve outra coleção Eloquent vazia, cujo `merge` chama
        // `getKey()` em cada linha de vacinação — que é array, não modelo.
        return $atendimentos
            ->toBase()
            ->map(fn (Atendimento $registro) => $this->linhaDoAtendimento($registro, $prestador))
            ->merge($vacinacoes->map(
                fn (Vacinacao $registro) => $this->linhaDaVacinacao($registro, $prestador),
            ))
            ->sortByDesc(fn (array $linha) => $linha['ordenacao'])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function linhaDoAtendimento(Atendimento $registro, Prestador $prestador): array
    {
        return [
            ...$this->linhaComum($registro, $prestador, $registro->atendido_em),
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
     * @return array<string, mixed>
     */
    private function linhaDaVacinacao(Vacinacao $registro, Prestador $prestador): array
    {
        return [
            ...$this->linhaComum($registro, $prestador, $registro->aplicado_em),
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
     * @return array<string, mixed>
     */
    private function linhaComum(
        Atendimento|Vacinacao $registro,
        Prestador $prestador,
        CarbonInterface $em,
    ): array {
        $animal = $registro->animal;

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
            'url' => $this->caminho($registro, $animal, $prestador),

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
