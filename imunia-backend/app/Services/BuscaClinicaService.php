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
 * A busca do ambiente clínico (V03, RF51).
 *
 * Duas portas, com alcances diferentes:
 *
 * - **Identificador exato** — CPF do tutor, código do animal, micro-chip. Quem
 *   o tem na mão está com o animal (ou com o tutor) à sua frente, e o
 *   atendimento não espera por ninguém: a resposta traz o cadastro inteiro,
 *   seja de que clínica for. Abrir a ficha o põe na carteira do prestador.
 * - **Nome** — de animal ou de tutor, só dentro da carteira. Nome não
 *   identifica ninguém, e a busca por nome em toda a base seria varredura de
 *   dados de terceiros.
 *
 * O encontro por identificador de um animal que a clínica ainda não acompanha
 * fica registrado (RF18b), e o tutor o vê no livro de acessos (T14).
 */
class BuscaClinicaService
{
    /**
     * A busca serve ao balcão: quem procura um animal determinado o reconhece
     * nas primeiras linhas, e uma lista maior do que isto é sinal de que o
     * termo precisa ser mais específico, não de que faltam resultados.
     */
    private const RESULTADOS_POR_SECAO = 24;

    public function __construct(private readonly CalendarioVacinalService $calendario) {}

    /**
     * @return array<string, mixed>
     */
    public function buscar(User $profissional, Prestador $prestador, TermoDeBusca $termo): array
    {
        $animais = $this->encontrados($prestador, $termo);

        // Pelo CPF a resposta é também o titular, com ou sem animal: é o que
        // permite a V04 e V05 seguirem para o cadastro do animal de um tutor
        // que ainda não tem nenhum.
        $tutor = $termo->tipo === TermoDeBusca::CPF && ! $termo->vazio()
            ? Tutor::query()->where('cpf', $termo->valor)->first()
            : null;

        // RF52b — a gravação do log é condição da exibição: acontece antes de a
        // resposta ser montada.
        $this->registrar($profissional, $prestador, $termo, $animais, $tutor);

        return [
            'termo' => $termo->original,
            'tipo' => $termo->tipo,

            'estado' => match (true) {
                $termo->vazio() => 'inicial',
                $animais->isEmpty() && $tutor === null => 'sem_resultado',
                default => 'normal',
            },

            'autorizados' => $this->cartoes($animais),
            'tutor' => $tutor === null ? null : ['nome' => $tutor->nome],

            // A seção de "existência fora do âmbito" deixou de existir: o
            // identificador exato agora traz o cadastro inteiro. A chave fica
            // nula enquanto as telas ainda a leem.
            'existencia' => null,
        ];
    }

    /**
     * @return Collection<int, Animal>
     */
    private function encontrados(Prestador $prestador, TermoDeBusca $termo): Collection
    {
        // Termo vazio não é busca por tudo: é a tela recém-aberta, que só
        // precisa saber em que prestador está.
        if ($termo->vazio()) {
            return new Collection;
        }

        $consulta = Animal::query()->with([
            'tutor',
            'vacinacoes' => fn ($vacinacoes) => $vacinacoes->orderBy('aplicado_em'),
            'prestadoresVinculados' => fn ($prestadores) => $prestadores->whereKey($prestador->id),
        ]);

        if (! $termo->chaveExata()) {
            $consulta->vinculadoA($prestador);
        }

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
     * `%` e `_` digitados pelo profissional são texto, e não curinga: sem o
     * escape, um termo de um caractere só percorreria a base inteira.
     */
    private function curinga(TermoDeBusca $termo): string
    {
        return '%'.addcslashes($termo->valor, '%_\\').'%';
    }

    /**
     * RF18b — o encontro por identificador de animal que a clínica ainda não
     * acompanha fica registrado, uma linha por titular, e o tutor o vê em T14.
     * A busca por nome não registra nada: ela só alcança a própria carteira.
     *
     * @param  Collection<int, Animal>  $animais
     */
    private function registrar(
        User $profissional,
        Prestador $prestador,
        TermoDeBusca $termo,
        Collection $animais,
        ?Tutor $tutor,
    ): void {
        $agora = now();

        // O titular sem animal algum também foi encontrado.
        if ($tutor !== null && $animais->isEmpty()) {
            RegistroDeAcesso::create([
                'prestador_id' => $prestador->id,
                'user_id' => $profissional->id,
                'tutor_id' => $tutor->id,
                'animal_id' => null,
                'natureza' => RegistroDeAcesso::BUSCA_POR_CPF,
                'ocorrido_em' => $agora,
            ]);

            return;
        }

        $alheios = $animais->filter(fn (Animal $animal) => $animal->prestadoresVinculados->isEmpty());

        if ($alheios->isEmpty()) {
            return;
        }

        RegistroDeAcesso::insert($alheios
            ->groupBy('tutor_id')
            ->map(fn (Collection $doTutor, int $tutorId) => [
                'prestador_id' => $prestador->id,
                'user_id' => $profissional->id,
                'tutor_id' => $tutorId,
                // Pelo CPF o encontrado é o titular, como em V04; pelo código e
                // pelo micro-chip, um animal determinado.
                'animal_id' => $termo->tipo === TermoDeBusca::CPF ? null : $doTutor->first()->id,
                'natureza' => $termo->naturezaDoRegistro(),
                'ocorrido_em' => $agora,
            ])
            ->values()
            ->all());
    }

    /**
     * O cartão do resultado: o mesmo vocabulário do painel do veterinário (V01),
     * acrescido da idade e do nome de quem o trouxe.
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
            'vinculado' => $animal->prestadoresVinculados->isNotEmpty(),
            'situacao' => $this->calendario->situacaoDaCarteira(
                $this->calendario->montarCarteiraCom($animal, $animal->vacinacoes),
            ),
        ])->all();
    }
}
