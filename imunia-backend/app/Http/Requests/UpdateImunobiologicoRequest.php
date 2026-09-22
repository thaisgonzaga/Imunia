<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * X01 — edição de item do catálogo (RF23). A chave não é campo desta
 * validação: é identificador estável (RN30) e não se reescreve depois de
 * criado. A situação ativo/inativo também fica fora — tem rota e
 * confirmação próprias (RF23b), e não se altera pelo mesmo formulário que
 * corrige um dado cadastral.
 */
class UpdateImunobiologicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $usuario */
        $usuario = $this->user();

        abort_if(! $usuario->admin_plataforma, 403, 'Esta área é da administração da plataforma.');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome_comercial' => ['required', 'string', 'max:255'],
            'nome_tecnico' => ['required', 'string', 'max:255'],
            'fabricante' => ['required', 'string', 'max:255'],
            'agentes_cobertos' => ['required', 'string', 'max:500'],
            'especie_destino' => ['required', 'in:cao,gato,ambas'],
            'classificacao' => ['required', 'in:essencial,nao_essencial'],
            'via_administracao_usual' => ['required', 'in:Subcutânea,Intramuscular'],
        ];
    }
}
