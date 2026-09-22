<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\SimuladorDeProtocoloService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * X02 — entradas hipotéticas do simulador. Nenhuma delas se refere a animal
 * existente: o simulador confere o cálculo sem tocar em dado real, e por isso
 * a data de nascimento e as datas de aplicação são números soltos, não chaves
 * estrangeiras para coisa alguma.
 */
class SimularCalendarioRequest extends FormRequest
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
            'versao_id' => ['required', 'integer', 'exists:versoes_protocolo,id'],
            // A busca do controlador já é por `(versao_id, imunobiologico_id)`,
            // e a linha de um item próprio não tem versão — a proteção existiria
            // por acidente. Declará-la aqui é o que impede que um conserto
            // futuro daquela consulta a desfaça sem perceber.
            'imunobiologico_id' => ['required', 'integer', Rule::exists('imunobiologicos', 'id')->whereNull('prestador_id')],

            // Com um caso escolhido, as entradas vêm dele; sem, vêm dos campos.
            'caso' => ['nullable', Rule::in(array_keys(SimuladorDeProtocoloService::CASOS))],
            'nascimento_em' => ['nullable', 'date'],
            'doses' => ['nullable', 'array', 'max:12'],
            'doses.*' => ['date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'versao_id' => 'versão',
            'imunobiologico_id' => 'imunobiológico',
            'nascimento_em' => 'data de nascimento',
            'doses' => 'datas de aplicação',
        ];
    }
}
