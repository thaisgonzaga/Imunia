<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAdministrado;
use App\Models\Prestador;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A04 — a prévia do agendamento, ao vivo enquanto o formulário é preenchido.
 *
 * Valida só o bloco de agendamento, e não os campos de identificação, porque a
 * prévia tem de responder desde a primeira escolha: exigir nome e fabricante
 * para mostrar em que data cairia a segunda dose faria a frase só aparecer com
 * o formulário inteiro pronto — quando ela já não ajuda a decidir.
 *
 * As regras do agendamento são as mesmas de `SalvarVacinaDoPrestadorRequest`,
 * de propósito: a prévia não pode aceitar o que a gravação recusa, senão
 * prometeria uma data que o servidor não vai gravar.
 */
class PreverAgendamentoRequest extends FormRequest
{
    use ResolvePrestadorAdministrado;

    public ?Prestador $prestador = null;

    public function authorize(): bool
    {
        [, $this->prestador] = $this->contextoAdministrativo($this);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'repete' => $this->boolean('repete'),
            'primeiro_reforco_diferente' => $this->boolean('primeiro_reforco_diferente'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return collect((new SalvarVacinaDoPrestadorRequest)->rules())
            ->only([
                'doses_primeira_vez',
                'intervalo_semanas',
                'repete',
                'periodicidade_valor',
                'periodicidade_unidade',
                'primeiro_reforco_diferente',
                'primeiro_reforco_valor',
                'primeiro_reforco_unidade',
            ])
            ->all();
    }
}
