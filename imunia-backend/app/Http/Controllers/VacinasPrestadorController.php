<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAdministrado;
use App\Http\Requests\PreverAgendamentoRequest;
use App\Http\Requests\SalvarVacinaDoPrestadorRequest;
use App\Models\Imunobiologico;
use App\Models\Prestador;
use App\Models\User;
use App\Services\CatalogoDoPrestador;
use App\Services\EquipeDoPrestador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A04 — as vacinas da clínica (RF23, RN30).
 *
 * A tela mostra dois acervos e permite escrever num só. O catálogo da
 * plataforma aparece porque a pessoa precisa saber o que já existe antes de
 * cadastrar o que falta — e aparece travado, porque o cálculo dele responde por
 * todas as clínicas e não por esta (RN31). O acervo próprio é dela: cria,
 * corrige e inativa.
 *
 * Como A02 e A03, a leitura é de quem tem vínculo e a escrita é de quem
 * administra: quem só atende precisa ver que vacinas a casa usa, e ver não é
 * administrar. Nada aqui alcança tutor, animal ou registro clínico (RN08) — o
 * item de catálogo é do estabelecimento, não do paciente.
 */
class VacinasPrestadorController extends Controller
{
    use ResolvePrestadorAdministrado;

    public function __construct(
        private readonly CatalogoDoPrestador $catalogo,
        private readonly EquipeDoPrestador $equipe,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$usuario, $prestador, $administra] = $this->contextoDoPrestador($request);

        return response()->json($this->estado($usuario, $prestador, $administra));
    }

    public function store(SalvarVacinaDoPrestadorRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $prestador = $request->prestador;

        $imunobiologico = $this->catalogo->criar($prestador, $request->validated());

        return response()->json([
            'message' => $imunobiologico->nome_comercial.' entrou no catálogo desta clínica '
                .'e já pode ser registrada em uma aplicação.',
            ...$this->estado($usuario, $prestador),
        ], 201);
    }

    /**
     * Corrigir o agendamento vale daqui para a frente e não recalcula o que já
     * foi mostrado ao tutor: a linha de parâmetros anterior permanece, e as
     * aplicações que a congelaram continuam explicadas por ela (RN32). É a
     * mesma promessa que X02 faz ao publicar uma versão, e a tela a repete
     * antes de confirmar.
     */
    public function update(SalvarVacinaDoPrestadorRequest $request, Imunobiologico $imunobiologico): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $prestador = $request->prestador;

        $this->catalogo->atualizar($imunobiologico, $request->validated());

        return response()->json([
            'message' => 'Vacina atualizada. As datas já calculadas continuam como estavam; '
                .'o novo agendamento vale para as aplicações a partir de agora.',
            ...$this->estado($usuario, $prestador),
        ]);
    }

    /**
     * Inativação, nunca exclusão (RF23b): tira o item do formulário de registro
     * desta clínica, e não toca nas aplicações já gravadas com ele — o
     * calendário delas segue sendo calculado, porque o histórico do animal não
     * é apagável por decisão administrativa.
     */
    public function inativar(Request $request, Imunobiologico $imunobiologico): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoAdministrativo($request);

        $this->exigirVacinaDaClinica($imunobiologico, $prestador);

        $this->catalogo->inativar($imunobiologico);

        return response()->json([
            'message' => $imunobiologico->nome_comercial.' deixa de aparecer no registro de aplicação. '
                .'As doses já registradas continuam no prontuário.',
            ...$this->estado($usuario, $prestador),
        ]);
    }

    public function reativar(Request $request, Imunobiologico $imunobiologico): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoAdministrativo($request);

        $this->exigirVacinaDaClinica($imunobiologico, $prestador);

        $this->catalogo->reativar($imunobiologico);

        return response()->json([
            'message' => $imunobiologico->nome_comercial.' voltou a aparecer no registro de aplicação.',
            ...$this->estado($usuario, $prestador),
        ]);
    }

    /**
     * A prévia do agendamento, ao vivo e sem gravar nada — o painel de cálculo
     * de V07, no formulário que define o cálculo.
     *
     * Responde a POST pelo tamanho da entrada e não por efeito, como o
     * simulador de X02.
     */
    public function previa(PreverAgendamentoRequest $request): JsonResponse
    {
        return response()->json($this->catalogo->previa($request->validated()));
    }

    /**
     * A mesma recusa da FormRequest, conferida de novo nas rotas que não passam
     * por ela. A duplicação é a que X01 já faz com `exigirAdminPlataforma` em
     * sete lugares: cada porta responde por si.
     */
    private function exigirVacinaDaClinica(Imunobiologico $imunobiologico, Prestador $prestador): void
    {
        (new SalvarVacinaDoPrestadorRequest)->exigirVacinaDaClinica($imunobiologico, $prestador);
    }

    /**
     * O corpo que toda resposta desta tela devolve.
     *
     * Vem inteiro, e não só a linha afetada, pelo mesmo argumento de A03: a
     * lista é de dezenas de itens, e devolvê-la evita que a tela recalcule por
     * conta própria o que mudou. Os cinco últimos campos são da moldura, e vêm
     * daqui porque vêm de A01, A02 e A03 também — um selo de convites
     * pendentes que some ao navegar para cá lê-se como convite resolvido.
     *
     * @return array<string, mixed>
     */
    private function estado(User $usuario, Prestador $prestador, ?bool $administra = null): array
    {
        $administra ??= $usuario->prestadoresComoAdministrador()->contains('id', $prestador->id);

        return [
            ...$this->catalogo->listar($prestador),
            'prestador' => ['id' => $prestador->id, 'nome' => $prestador->nome],
            'pode_administrar' => $administra,
            'opcoes' => $this->opcoes(),
            'equipe' => $this->equipe->contagens($this->equipe->listar($prestador, $usuario, $administra)),
            'vinculos' => $this->vinculosAdministradosDe($usuario),
            'contexto_clinico' => $this->contextoClinicoDivergente($usuario, $prestador),
            'atende_aqui' => $this->atendeNoPrestador($usuario, $prestador),
            'vinculos_clinicos' => $this->vinculosClinicosDe($usuario),
        ];
    }

    /**
     * As listas fechadas do formulário vêm do servidor pelo mesmo motivo dos
     * rótulos de tipo em A02: são as mesmas que a validação aplica, e mantê-las
     * só na tela seria mantê-las em dois lugares.
     *
     * @return array<string, mixed>
     */
    private function opcoes(): array
    {
        return [
            'especies' => [
                ['valor' => 'cao', 'rotulo' => 'Cão'],
                ['valor' => 'gato', 'rotulo' => 'Gato'],
                ['valor' => 'ambas', 'rotulo' => 'Cão e gato'],
            ],
            'vias' => [
                ['valor' => 'Subcutânea', 'rotulo' => 'Subcutânea'],
                ['valor' => 'Intramuscular', 'rotulo' => 'Intramuscular'],
            ],
            'intervalos_semanas' => [
                ['valor' => 2, 'rotulo' => '2 semanas'],
                ['valor' => 3, 'rotulo' => '3 semanas'],
                ['valor' => 4, 'rotulo' => '4 semanas'],
            ],
            'unidades' => [
                ['valor' => 'meses', 'rotulo' => 'meses'],
                ['valor' => 'anos', 'rotulo' => 'anos'],
            ],
        ];
    }
}
