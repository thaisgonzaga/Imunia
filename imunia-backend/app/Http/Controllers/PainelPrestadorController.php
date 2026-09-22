<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAdministrado;
use App\Models\Prestador;
use App\Services\EquipeDoPrestador;
use App\Services\PendenciasDeConfiguracao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A01 — painel administrativo do prestador (RF07, RF08, RF09).
 *
 * O que este painel **não** devolve é tão deliberado quanto o que devolve: não
 * há contagem de animais, de atendimentos, de vacinas aplicadas nem de
 * autorizações. RN08 mantém o papel administrativo fora do dado clínico, e um
 * indicador agregado continua sendo dado clínico — saber que a clínica aplicou
 * 240 vacinas no mês é informação sobre os animais atendidos ali.
 *
 * A escassez do painel é, portanto, o argumento; e é por isso que a tela
 * explica a ausência em vez de apenas produzi-la.
 */
class PainelPrestadorController extends Controller
{
    use ResolvePrestadorAdministrado;

    public function __construct(
        private readonly EquipeDoPrestador $equipe,
        private readonly PendenciasDeConfiguracao $pendencias,
    ) {}

    public function show(Request $request): JsonResponse
    {
        [$usuario, $prestador, $administra] = $this->contextoDoPrestador($request);

        $membros = $this->equipe->listar($prestador, $usuario, $administra);

        return response()->json([
            'prestador' => $this->representar($prestador),
            'equipe' => $this->equipe->contagens($membros),
            // O bloqueio vem para os dois: sem responsável técnico o
            // estabelecimento não registra nada, e isso interessa a quem atende
            // ali tanto quanto a quem administra (RF07c). As pendências de
            // configuração, não — são a lista de tarefas de quem pode resolvê-las.
            'bloqueio' => $this->pendencias->bloqueio($prestador),
            'pendencias' => $administra ? $this->pendencias->listar($prestador, $membros) : [],
            'pode_administrar' => $administra,
            'concessao' => $this->equipe->concessao($prestador, $usuario, $membros, $administra),
            'administrada_por' => $this->equipe->administradores($prestador),
            'vinculos' => $this->vinculosAdministradosDe($usuario),
            'contexto_clinico' => $this->contextoClinicoDivergente($usuario, $prestador),
            'atende_aqui' => $this->atendeNoPrestador($usuario, $prestador),
            'vinculos_clinicos' => $this->vinculosClinicosDe($usuario),
        ]);
    }

    /**
     * O cartão de identificação de A01. CNPJ e telefone saem crus: a máscara é
     * da tela, como em toda a interface (`src/lib/masks.js`).
     *
     * @return array<string, mixed>
     */
    private function representar(Prestador $prestador): array
    {
        return [
            'id' => $prestador->id,
            'nome' => $prestador->nome,
            'tipo' => $prestador->tipo,
            'tipo_rotulo' => $prestador->tipoRotulo(),
            'cnpj' => $prestador->cnpj,
            'telefone' => $prestador->telefone,
            'endereco' => $prestador->endereco,
            'cep' => $prestador->cep,
            'municipio' => $prestador->municipio,
            'uf' => $prestador->uf,
            'responsavel_tecnico_nome' => $prestador->responsavel_tecnico_nome,
            'responsavel_tecnico_crmv' => $prestador->responsavelTecnicoCrmv(),
        ];
    }
}
