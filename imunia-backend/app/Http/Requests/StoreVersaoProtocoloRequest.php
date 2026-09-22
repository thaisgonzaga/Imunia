<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * X02 — abertura de uma versão nova a partir da vigente (RF24). O ator é a
 * administração da plataforma, papel global e sem relação com prestador ou
 * tutor — daí a autorização checar a coluna do usuário, e não um vínculo.
 */
class StoreVersaoProtocoloRequest extends FormRequest
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
            // O rótulo é o que identifica a versão para sempre, inclusive nos
            // registros calculados sob ela: não se repete nem se reaproveita.
            'rotulo' => ['required', 'string', 'max:20', 'unique:versoes_protocolo,rotulo'],
            'base' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rotulo' => 'identificação da versão',
            'base' => 'diretriz de origem',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rotulo.unique' => 'Já existe uma versão com esta identificação. Cada versão tem a sua, e ela não se reaproveita.',
        ];
    }
}
