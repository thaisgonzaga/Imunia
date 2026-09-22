<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Models\Animal;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * V12 — registrar o óbito do animal (RF22).
 *
 * O âmbito é resolvido em `authorize()`, na mesma ordem de respostas de V07 e
 * V08: vínculo, inscrição, animal, autorização. O óbito muda o estado do
 * animal inteiro — para o tutor e para todo prestador autorizado —, e por isso
 * exige autorização vigente sem a exceção que o atendimento tem: não há aqui o
 * "registrar no prontuário desta clínica" de V08, porque o óbito não é
 * prontuário de uma clínica.
 *
 * O que não está aqui, de propósito: a recusa por óbito já registrado. Ela não
 * é erro de formulário, é estado da tela (§V12), e responde 409 no controlador
 * com a data e o autor — o que o segundo veterinário precisa ler não é "campo
 * inválido", é "já foi registrado, por fulano, em tal data".
 */
class RegistrarObitoRequest extends FormRequest
{
    use ResolvePrestadorAtivo;

    public ?User $profissional = null;

    public ?Prestador $prestador = null;

    public ?Animal $animal = null;

    public ?string $crmv = null;

    public function authorize(): bool
    {
        [$this->profissional, $this->prestador] = $this->contextoClinico($this);

        // RN21 — só médico-veterinário com CRMV registrado cria registro
        // clínico, e o óbito é registro clínico: cessa calendário e lembretes
        // do animal de todo mundo, não só desta clínica.
        $this->crmv = $this->crmvExigido($this->profissional, $this->prestador);

        $this->animal = Animal::query()->where('codigo', $this->route('codigo'))->first();

        abort_if($this->animal === null, 404, 'Animal não encontrado.');

        abort_if(
            ! $this->animal->autorizacoes()->where('prestador_id', $this->prestador->id)->vigente()->exists(),
            403,
            'Este animal não está sob autorização vigente do tutor. Solicite o acesso antes de registrar.',
        );

        return true;
    }

    protected function prepareForValidation(): void
    {
        // A causa é opcional (§V12): em branco e ausente dizem a mesma coisa,
        // e precisam chegar iguais à validação.
        if (is_string($this->input('causa'))) {
            $causa = trim($this->input('causa'));

            $this->merge(['causa' => $causa === '' ? null : $causa]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // RF22 — o registro é do óbito **com data**. Futuro não é óbito, é
            // previsão; e um animal não morre antes de nascer — quando a data
            // de nascimento é conhecida, ela é o piso.
            'em' => [
                'required',
                'date',
                'before_or_equal:today',
                ...($this->animal?->nascimento_em === null
                    ? []
                    : ['after_or_equal:'.$this->animal->nascimento_em->toDateString()]),
            ],
            'causa' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'em.required' => 'Informe a data do óbito.',
            'em.date' => 'Informe uma data válida.',
            'em.before_or_equal' => 'A data do óbito não pode estar no futuro.',
            'em.after_or_equal' => 'A data do óbito não pode ser anterior ao nascimento do animal.',
            'causa.max' => 'Descreva a causa em até 160 caracteres.',
        ];
    }
}
