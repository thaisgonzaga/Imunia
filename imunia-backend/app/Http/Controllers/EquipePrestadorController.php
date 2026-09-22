<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAdministrado;
use App\Http\Requests\ConvidarVeterinarioRequest;
use App\Http\Requests\StorePrestadorRequest;
use App\Models\Convite;
use App\Models\Prestador;
use App\Models\User;
use App\Services\EquipeDoPrestador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A03 — equipe do prestador (RF09, RF10).
 *
 * Toda escrita devolve a lista inteira, e não só a linha afetada. Encerrar um
 * vínculo muda o que as outras linhas oferecem — a pessoa que era o único
 * administrador deixa de sê-lo, o convite recém-enviado altera as contagens —,
 * e devolver só a linha tocada obrigaria a tela a recalcular o resto por conta
 * própria. A equipe de um prestador é pequena; a lista inteira é barata.
 */
class EquipePrestadorController extends Controller
{
    use ResolvePrestadorAdministrado;

    public function __construct(private readonly EquipeDoPrestador $equipe) {}

    public function index(Request $request): JsonResponse
    {
        [$usuario, $prestador, $administra] = $this->contextoDoPrestador($request);

        return response()->json($this->estado($prestador, $usuario, $administra));
    }

    /**
     * RF09 — convida por endereço de correio eletrônico, com CRMV e UF
     * obrigatórios. O convidado define a própria senha, e o próprio nome, no
     * primeiro acesso (P07).
     */
    public function store(ConvidarVeterinarioRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $prestador = $request->prestador();

        $this->equipe->convidar($prestador, $usuario, $request->validated());

        return response()->json([
            'message' => 'Convite enviado para '.$request->string('email').'.',
            ...$this->estado($prestador, $usuario),
        ], 201);
    }

    /**
     * RF14a — o convite expira e pode ser reenviado. O prazo recomeça e o
     * token anterior deixa de valer no mesmo ato.
     */
    public function reenviar(Request $request, int $vinculo): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoAdministrativo($request);

        $membro = $this->equipe->membroDoVinculo($prestador, $vinculo);

        $this->equipe->reenviar($prestador, $membro);

        return response()->json([
            'message' => 'Convite reenviado para '.$membro->email
                .'. O prazo recomeça: vale por mais '.Convite::VALIDADE_EM_DIAS.' dias.',
            ...$this->estado($prestador, $usuario),
        ], 202);
    }

    /**
     * Concede a administração da conta a quem já atende aqui (RF09, RN05).
     *
     * Só o responsável técnico chega até aqui: administrar é convidar,
     * desligar e alterar o cadastro do estabelecimento, e quem responde
     * tecnicamente por ele é quem decide a quem esse poder cabe. A recusa é do
     * servidor, e não apenas do botão escondido na tela.
     */
    public function conceder(Request $request, int $vinculo): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoAdministrativo($request);

        $linha = $this->recusarAdministracaoImpedida($prestador, $usuario, $vinculo);
        $membro = $this->equipe->membroDoVinculo($prestador, $vinculo);

        // Conceder de novo termina no mesmo lugar: o pedido repetido não é
        // erro, é a mesma intenção — como no encerramento.
        if ($linha['administrador']) {
            return response()->json([
                'message' => $this->identificar($membro).' já administra a conta.',
                ...$this->estado($prestador, $usuario),
            ]);
        }

        $this->equipe->conceder($prestador, $membro);

        return response()->json([
            'message' => $this->identificar($membro).' passa a administrar a conta: pode convidar '
                .'profissionais, encerrar vínculos e alterar os dados do prestador.',
            ...$this->estado($prestador, $usuario),
        ], 201);
    }

    /**
     * Retira a administração sem tocar no vínculo clínico: quem perde o papel
     * continua atendendo ali, e a autoria dos registros segue com quem os
     * assinou.
     */
    public function revogar(Request $request, int $vinculo): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoAdministrativo($request);

        $linha = $this->recusarAdministracaoImpedida($prestador, $usuario, $vinculo);
        $membro = $this->equipe->membroDoVinculo($prestador, $vinculo);

        abort_if(! $linha['administrador'], 422, $this->identificar($membro).' não administra a conta.');

        $this->equipe->revogar($prestador, $membro);

        return response()->json([
            'message' => $this->identificar($membro).' deixa de administrar a conta. O vínculo de '
                .'veterinário continua, e os registros assinados permanecem no prontuário.',
            ...$this->estado($prestador, $usuario),
        ]);
    }

    /**
     * RF10 — encerra o vínculo. Verbo `DELETE` embora nada seja removido, pelo
     * mesmo motivo de `DELETE /api/autorizacoes/{autorizacao}`: o que a rota
     * encerra é a relação, e a linha que a comprova permanece.
     */
    public function encerrar(Request $request, int $vinculo): JsonResponse
    {
        [$usuario, $prestador] = $this->contextoAdministrativo($request);

        $membro = $this->equipe->membroDoVinculo($prestador, $vinculo);

        $this->recusarEncerramentoImpedido($prestador, $usuario, $membro, $vinculo);

        $this->equipe->encerrar($prestador, $membro);

        return response()->json([
            'message' => 'Vínculo encerrado. Os registros '.$this->autoria($membro)
                .' continuam no prontuário do prestador, com o nome e o CRMV de quem os assinou.',
            ...$this->estado($prestador, $usuario),
        ]);
    }

    /**
     * As três recusas de encerramento moram na representação da linha, para que
     * a tela possa explicar o botão ausente (RNF09). Aqui elas são conferidas
     * de novo: a interface esconder a ação é conveniência, e a recusa é do
     * servidor.
     */
    private function recusarEncerramentoImpedido(
        Prestador $prestador,
        User $usuario,
        User $membro,
        int $vinculo,
    ): void {
        $linha = collect($this->equipe->listar($prestador, $usuario))
            ->firstWhere('vinculo', $vinculo);

        // Encerrar de novo termina no mesmo lugar, com a data original
        // preservada: o pedido repetido não é erro, é a mesma intenção.
        if ($linha === null || $linha['situacao'] === EquipeDoPrestador::ENCERRADO) {
            return;
        }

        abort_if($linha['impedimento'] !== null, 422, (string) $linha['impedimento']);
    }

    /**
     * As duas recusas da concessão, conferidas no servidor pelo mesmo motivo
     * das do encerramento: a interface esconder a ação é conveniência.
     *
     * @return array<string, mixed> a linha da equipe, que o chamador ainda usa
     */
    private function recusarAdministracaoImpedida(Prestador $prestador, User $usuario, int $vinculo): array
    {
        $membros = $this->equipe->listar($prestador, $usuario);
        $concessao = $this->equipe->concessao($prestador, $usuario, $membros);

        abort_unless($concessao['pode_conceder'], 403, (string) $concessao['restricao']);

        $linha = collect($membros)->firstWhere('vinculo', $vinculo);

        abort_if($linha === null, 404, 'Vínculo não encontrado.');
        abort_if($linha['impedimento_administracao'] !== null, 422, (string) $linha['impedimento_administracao']);

        return $linha;
    }

    /**
     * @return array<string, mixed>
     */
    private function estado(Prestador $prestador, User $usuario, bool $administra = true): array
    {
        $membros = $this->equipe->listar($prestador, $usuario, $administra);

        return [
            'pode_administrar' => $administra,
            // A quem pedir, para quem só atende aqui. Vem sempre pelo mesmo
            // motivo das contagens: a tela decide o que fazer com o dado.
            'administrada_por' => $this->equipe->administradores($prestador),
            'prestador' => [
                'id' => $prestador->id,
                'nome' => $prestador->nome,
                'tipo_rotulo' => $prestador->tipoRotulo(),
            ],
            'membros' => $membros,
            'contagens' => $this->equipe->contagens($membros),
            'concessao' => $this->equipe->concessao($prestador, $usuario, $membros, $administra),
            'opcoes' => ['ufs' => StorePrestadorRequest::UFS],
            'vinculos' => $this->vinculosAdministradosDe($usuario),
            'contexto_clinico' => $this->contextoClinicoDivergente($usuario, $prestador),
            'atende_aqui' => $this->atendeNoPrestador($usuario, $prestador),
            'vinculos_clinicos' => $this->vinculosClinicosDe($usuario),
        ];
    }

    /**
     * Enquanto o convidado não define o próprio nome, é pelo endereço que ele
     * é identificado — na mensagem tanto quanto na linha da equipe.
     */
    private function autoria(User $membro): string
    {
        return 'de '.$this->identificar($membro);
    }

    private function identificar(User $membro): string
    {
        return blank($membro->name) ? $membro->email : $membro->name;
    }
}
