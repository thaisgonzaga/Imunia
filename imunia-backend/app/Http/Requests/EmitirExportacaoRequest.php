<?php

namespace App\Http\Requests;

use App\Services\ExportacaoDeHistoricoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmitirExportacaoRequest extends FormRequest
{
    /**
     * A titularidade do animal é julgada no controlador, como em T05 e T07:
     * aqui só se valida a forma do pedido.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'conteudo' => ['required', Rule::in(ExportacaoDeHistoricoService::CONTEUDOS)],
            // O período só recorta o histórico; a carteira é o estado presente
            // da vacinação, e "carteira dos últimos 6 meses" não descreve coisa
            // alguma. A tela nem oferece o campo — a regra cobre o pedido
            // montado à mão.
            'meses' => [
                'nullable',
                'prohibited_if:conteudo,carteira',
                Rule::in(ExportacaoDeHistoricoService::PERIODOS_EM_MESES),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'conteudo.required' => 'Escolha o que o documento deve conter.',
            'conteudo.in' => 'Escolha entre a carteira de vacinação e o histórico completo.',
            'meses.prohibited_if' => 'A carteira de vacinação não tem recorte de período.',
            'meses.in' => 'Escolha um dos períodos oferecidos.',
        ];
    }

    public function conteudo(): string
    {
        return $this->validated('conteudo');
    }

    public function meses(): ?int
    {
        $meses = $this->validated('meses');

        return $meses === null ? null : (int) $meses;
    }
}
