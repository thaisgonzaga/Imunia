<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Http\Requests\CadastrarAnimalNaClinicaRequest;
use App\Http\Requests\CaracterizarAnimalRequest;
use App\Models\Animal;
use App\Models\Prestador;
use App\Models\RegistroDeAcesso;
use App\Models\Tutor;
use App\Models\User;
use App\Support\NomeSemelhante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * V05 — cadastrar animal no atendimento (RF16, RF19, RF20).
 *
 * As duas portas do mesmo ato profissional: o cadastro novo, com identificação
 * e caracterização de uma vez; e a consolidação (`caracterizar`), que completa
 * o cadastro preliminar iniciado pelo tutor sem jamais criar um segundo (RN19).
 *
 * O cadastro criado aqui **não** entra no âmbito do prestador: a autorização é
 * ato do tutor (RF36, RF37), e não subproduto do balcão. O sucesso conduz à
 * ficha, que no estado sem autorização já oferece o pedido de V10 — é o
 * encadeamento que RF13c promete, tela a tela.
 */
class CadastroDeAnimalController extends Controller
{
    use ResolvePrestadorAtivo;

    public function store(CadastrarAnimalNaClinicaRequest $request): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $tutor = Tutor::query()->where('cpf', $request->cpfDoTutor())->first();

        // O caminho desenhado verifica o CPF antes do formulário (a busca de
        // V03, como em V04); chegar aqui sem cadastro é a janela entre a
        // verificação e o envio. A frase é a do vazio de V03.
        if ($tutor === null) {
            throw ValidationException::withMessages([
                'cpf' => 'Nenhum cadastro corresponde a este CPF. Cadastre o tutor primeiro — o animal vem em seguida.',
            ]);
        }

        $this->recusarMicrochipAlheio($tutor, $request->validated('microchip'));

        $duplicado = $this->duplicidadeProvavel(
            $tutor,
            $request->validated('nome'),
            $request->validated('especie'),
            $request->validated('microchip'),
        );

        // RF20a — o alerta precede a confirmação. Não é recusa: o segundo
        // envio, já ciente, cadastra; a prevenção importa mais do que a
        // correção porque a fusão posterior não é oferecida (limitação
        // declarada de RF20).
        if ($duplicado !== null && ! $request->boolean('confirmar_duplicidade')) {
            return $this->alertaDeDuplicidade($profissional, $prestador, $tutor, $duplicado);
        }

        $animal = $tutor->animais()->create([
            'nome' => $request->validated('nome'),
            'especie' => $request->validated('especie'),
            'sexo' => $request->validated('sexo'),
            'nascimento_em' => $request->nascimentoEm(),
            'nascimento_exato' => $request->nascimentoExato(),
            'raca' => $request->validated('raca'),
            'pelagem' => $request->validated('pelagem'),
            'situacao_reprodutiva' => $request->validated('situacao_reprodutiva'),
            'microchip' => $request->validated('microchip'),

            // RN17 fala do cadastro *iniciado pelo tutor*: este nasce de um
            // médico-veterinário em atendimento, e preliminar ele nunca foi —
            // campo de caracterização em branco aqui é escolha profissional,
            // não pendência. O autor fica carimbado (RF19c).
            'caracterizado_em' => now(),
            'caracterizado_por_user_id' => $profissional->id,
        ]);

        return response()->json([
            'message' => "Animal cadastrado com o código {$animal->codigo}.",
            'animal' => [
                'codigo' => $animal->codigo,
                'nome' => $animal->nome,
                'especie' => $animal->especie,
            ],
        ], 201);
    }

    /**
     * O que a consolidação precisa saber antes de abrir (RF20b): a
     * identificação que já existe e o que o tutor declarou — apresentado ao
     * veterinário para confirmação ou correção (RF19b).
     */
    public function opcoes(Request $request, string $codigo): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $animal = $this->animalAutorizado($prestador, $codigo);

        return response()->json([
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'vinculos' => $this->vinculosDe($profissional),
            'animal' => [
                'codigo' => $animal->codigo,
                'nome' => $animal->nome,
                'especie' => $animal->especie,
                'foto_url' => $animal->fotoUrl(),
                'tutor' => $animal->tutor->nome,
                'preliminar' => $animal->preliminar(),

                // RF19b — o declarado pelo tutor, para confirmar ou corrigir.
                'sexo' => $animal->sexo,
                'nascimento_em' => $animal->nascimento_em?->toDateString(),
                'nascimento_exato' => $animal->nascimento_exato,

                'raca' => $animal->raca,
                'pelagem' => $animal->pelagem,
                'situacao_reprodutiva' => $animal->situacao_reprodutiva,
                'microchip' => $animal->microchip,
            ],
        ]);
    }

    /**
     * RF19 — completar e manter a caracterização. O mesmo verbo serve à
     * primeira vez e à manutenção: o que muda é o que já está gravado, e cada
     * salvamento reescreve autor e data (RF19c).
     */
    public function caracterizar(CaracterizarAnimalRequest $request, string $codigo): JsonResponse
    {
        [$profissional, $prestador] = $this->contextoClinico($request);

        $animal = $this->animalAutorizado($prestador, $codigo);

        $this->recusarMicrochipAlheio($animal->tutor, $request->validated('microchip'), $animal);

        $animal->update([
            'sexo' => $request->validated('sexo'),
            'nascimento_em' => $request->nascimentoEm(),
            'nascimento_exato' => $request->nascimentoExato(),
            'raca' => $request->validated('raca'),
            'pelagem' => $request->validated('pelagem'),
            'situacao_reprodutiva' => $request->validated('situacao_reprodutiva'),
            'microchip' => $request->validated('microchip'),
            'caracterizado_em' => now(),
            'caracterizado_por_user_id' => $profissional->id,
        ]);

        return response()->json([
            'message' => "Caracterização de {$animal->nome} registrada.",
            'animal' => [
                'codigo' => $animal->codigo,
                'preliminar' => $animal->preliminar(),
            ],
        ]);
    }

    /**
     * RF20a — "para o mesmo tutor, animal ativo de mesma espécie e nome
     * semelhante", mais o micro-chip igual, que não é semelhança: é o mesmo
     * animal por definição (RN15 por analogia). O óbito fica fora, como em
     * T03: outro cão com o nome do que morreu não é duplicidade.
     */
    private function duplicidadeProvavel(
        Tutor $tutor,
        string $nome,
        string $especie,
        ?string $microchip,
    ): ?Animal {
        $ativos = $tutor->animais()->whereNull('obito_em')->get();

        $peloChip = $microchip === null
            ? null
            : $ativos->first(fn (Animal $animal) => $animal->microchip === $microchip);

        return $peloChip
            ?? $ativos->first(
                fn (Animal $animal) => $animal->especie === $especie
                    && NomeSemelhante::entre($animal->nome, $nome),
            );
    }

    /**
     * O alerta identifica o cadastro possivelmente equivalente (RF20a) — mas o
     * quanto ele identifica depende do âmbito. Sob autorização vigente, o
     * cartão completo, com o caminho para a ficha. Fora dele, o mínimo que
     * RF18a admite para animal sem autorização — nome e espécie, sem código —
     * e a revelação fica registrada (RF18b), porque descobrir pelo formulário
     * que o tutor tem um animal parecido é descobrir por busca com outro nome.
     */
    private function alertaDeDuplicidade(
        User $profissional,
        Prestador $prestador,
        Tutor $tutor,
        Animal $duplicado,
    ): JsonResponse {
        $autorizado = Animal::query()
            ->sobAutorizacaoVigenteDe($prestador)
            ->whereKey($duplicado->id)
            ->exists();

        if ($autorizado) {
            return response()->json([
                'message' => 'Este tutor já tem um cadastro parecido com este.',
                'duplicado' => [
                    ...$duplicado->paraListagem(),
                    'ambito' => 'autorizado',
                ],
            ], 409);
        }

        RegistroDeAcesso::create([
            'prestador_id' => $prestador->id,
            'user_id' => $profissional->id,
            'tutor_id' => $tutor->id,
            'animal_id' => $duplicado->id,
            'natureza' => RegistroDeAcesso::ALERTA_DE_DUPLICIDADE,
            'ocorrido_em' => now(),
        ]);

        return response()->json([
            'message' => 'Este tutor já tem um cadastro parecido com este.',
            'duplicado' => [
                'nome' => $duplicado->nome,
                'especie' => $duplicado->especie,
                'ambito' => 'fora_do_ambito',
            ],
        ], 409);
    }

    /**
     * O micro-chip identifica um animal como o código o faz, e a coluna é
     * única. Em cadastro alheio, a recusa diz só isso — de quem é e o que é
     * permanecem fora da resposta (RN12). O próprio animal fica de fora da
     * conferência na manutenção, ou salvar sem trocar o chip acusaria
     * conflito com ele mesmo.
     */
    private function recusarMicrochipAlheio(Tutor $tutor, ?string $microchip, ?Animal $proprio = null): void
    {
        if ($microchip === null) {
            return;
        }

        $existente = Animal::query()->where('microchip', $microchip)->first();

        if ($existente === null || $existente->is($proprio) || $existente->tutor_id === $tutor->id) {
            return;
        }

        throw ValidationException::withMessages([
            'microchip' => 'Este micro-chip já está registrado em outro cadastro. Confira o número no leitor.',
        ]);
    }

    /**
     * A caracterização exige autorização vigente (RN48): ela é escrita sobre o
     * cadastro, não registro próprio do prestador. O código inexistente é 404
     * para todos; o animal fora do âmbito responde 403 com o caminho — a
     * ficha, que já sabe pedir a autorização (V10).
     */
    private function animalAutorizado(Prestador $prestador, string $codigo): Animal
    {
        $animal = Animal::query()->where('codigo', $codigo)->first();

        abort_if($animal === null, 404, 'Animal não encontrado.');

        $autorizado = Animal::query()
            ->sobAutorizacaoVigenteDe($prestador)
            ->whereKey($animal->id)
            ->exists();

        abort_unless(
            $autorizado,
            403,
            'Sem autorização vigente para este animal. Peça a autorização ao tutor pela ficha.',
        );

        return $animal;
    }
}
