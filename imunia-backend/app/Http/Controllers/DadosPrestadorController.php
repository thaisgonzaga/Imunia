<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvePrestadorAdministrado;
use App\Http\Requests\AtualizarPrestadorRequest;
use App\Http\Requests\StorePrestadorRequest;
use App\Models\Prestador;
use App\Models\User;
use App\Services\AtualizacaoDoPrestador;
use App\Services\EquipeDoPrestador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A02 — dados cadastrais do prestador (RF08).
 *
 * Os valores saem crus, sem máscara: é formulário, e quem mascara é o campo na
 * tela. Os rótulos de tipo, ao contrário, vêm do servidor — `ROTULOS_DE_TIPO`
 * é privado ao modelo de propósito (RF07a), para que o diretório, o painel e
 * este formulário digam a mesma coisa sobre o mesmo estabelecimento.
 */
class DadosPrestadorController extends Controller
{
    use ResolvePrestadorAdministrado;

    public function __construct(
        private readonly AtualizacaoDoPrestador $atualizacao,
        private readonly EquipeDoPrestador $equipe,
    ) {}

    public function show(Request $request): JsonResponse
    {
        [$usuario, $prestador, $administra] = $this->contextoDoPrestador($request);

        return response()->json([
            'prestador' => $this->representar($prestador),
            'opcoes' => $this->opcoes(),
            // O histórico de alterações é a memória de quem edita: quem só
            // atende aqui vê o cadastro como ele está hoje.
            'historico' => $administra ? $this->atualizacao->historico($prestador) : [],
            'pode_administrar' => $administra,
            'administrada_por' => $this->equipe->administradores($prestador),
            // Alimenta o selo de convites pendentes da moldura. Vem de todas as
            // três telas porque o selo é da moldura, e um número que some ao
            // navegar de A01 para cá lê-se como convite resolvido.
            'equipe' => $this->equipe->contagens($this->equipe->listar($prestador, $usuario, $administra)),
            'vinculos' => $this->vinculosAdministradosDe($usuario),
            'contexto_clinico' => $this->contextoClinicoDivergente($usuario, $prestador),
            'atende_aqui' => $this->atendeNoPrestador($usuario, $prestador),
            'vinculos_clinicos' => $this->vinculosClinicosDe($usuario),
        ]);
    }

    public function update(AtualizarPrestadorRequest $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();
        $prestador = $request->prestador();

        $avisos = $this->atualizacao->aplicar($prestador, $usuario, $request->validated());

        return response()->json([
            'message' => 'Dados atualizados.',
            'prestador' => $this->representar($prestador),
            'historico' => $this->atualizacao->historico($prestador),
            // O que a alteração produziu, decidido por quem comparou os valores.
            'avisos' => $avisos,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function representar(Prestador $prestador): array
    {
        return [
            'id' => $prestador->id,
            'tipo' => $prestador->tipo,
            'nome' => $prestador->nome,
            'cnpj' => $prestador->cnpj,
            'telefone' => $prestador->telefone,
            'endereco' => $prestador->endereco,
            'cep' => $prestador->cep,
            'municipio' => $prestador->municipio,
            'uf' => $prestador->uf,
            'responsavel_tecnico_nome' => $prestador->responsavel_tecnico_nome,
            'responsavel_tecnico_crmv' => $prestador->responsavel_tecnico_crmv,
            'responsavel_tecnico_crmv_uf' => $prestador->responsavel_tecnico_crmv_uf,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function opcoes(): array
    {
        return [
            'tipos' => collect(['clinica', 'hospital', 'autonomo'])
                ->map(fn (string $tipo) => [
                    'valor' => $tipo,
                    'rotulo' => (new Prestador(['tipo' => $tipo]))->tipoRotulo(),
                ])
                ->all(),
            'ufs' => StorePrestadorRequest::UFS,
        ];
    }
}
