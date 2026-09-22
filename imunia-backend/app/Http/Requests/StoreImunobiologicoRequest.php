<?php

namespace App\Http\Requests;

use App\Models\Imunobiologico;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * X01 — cadastro de item do catálogo (RF23). O ator é a administração da
 * plataforma, papel global e sem relação com prestador ou tutor — daí a
 * autorização checar a coluna do usuário, e não um vínculo.
 */
class StoreImunobiologicoRequest extends FormRequest
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

    /**
     * A chave é o identificador estável usado pelo protocolo e pelo cálculo
     * do calendário (RN30, RN31): nasce da denominação técnica no cadastro e
     * nunca é reescrita depois — por isso não é campo do formulário, e é
     * gerada aqui, e não no controlador, para que o serviço só veja dados já
     * completos.
     */
    public function comChave(): array
    {
        $chave = Imunobiologico::chaveDisponivel(Str::slug($this->input('nome_tecnico')));

        return [...$this->validated(), 'chave' => $chave, 'ativo' => true];
    }
}
