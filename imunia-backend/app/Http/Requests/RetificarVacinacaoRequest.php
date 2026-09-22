<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Models\Animal;
use App\Models\Prestador;
use App\Models\User;
use App\Models\Vacinacao;
use App\Rules\ValidadeMesAno;
use App\Services\RetificacaoDeRegistroService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * V09 — retificação de uma aplicação de vacina (RF33, RN26, RN27).
 *
 * Irmã de `RetificarAtendimentoRequest`: mesma ordem de recusas, mesmo motivo
 * obrigatório, mesma exigência de que algo tenha mudado. O que muda é a lista
 * de campos, que aqui não é texto livre — é lote, validade, via e ordem da
 * dose, cada um com a regra que V07 já lhes deu.
 *
 * **A confirmação de RF25c não é repetida aqui**, e a ausência é deliberada. O
 * que aquela regra exige é o consentimento de quem está prestes a aplicar um
 * lote vencido — decisão clínica, tomada diante do animal. Numa retificação a
 * aplicação já aconteceu meses atrás; pedir que o profissional "confirme" um
 * fato passado seria pedir consentimento para o que não depende mais dele. O
 * que RF25c pede em substância — que o registro fique sinalizado — continua
 * valendo, e a marca é recalculada na gravação a partir das datas corrigidas.
 */
class RetificarVacinacaoRequest extends FormRequest
{
    use ResolvePrestadorAtivo;

    public ?User $profissional = null;

    public ?Prestador $prestador = null;

    public ?Animal $animal = null;

    public ?Vacinacao $original = null;

    public function authorize(): bool
    {
        [$this->profissional, $this->prestador] = $this->contextoClinico($this);

        $this->crmvExigido($this->profissional, $this->prestador);

        $this->animal = Animal::query()->where('codigo', $this->route('codigo'))->first();

        abort_if($this->animal === null, 404, 'Animal não encontrado.');

        /** @var Vacinacao|null $vacinacao */
        $vacinacao = $this->animal->vacinacoes()->find($this->route('vacinacao'));

        abort_if($vacinacao === null, 404, 'Aplicação não encontrada.');

        abort_if(
            ! $this->animal->autorizacoes()->where('prestador_id', $this->prestador->id)->vigente()->exists(),
            403,
            'Este animal não está sob autorização vigente do tutor. Solicite o acesso antes de retificar.',
        );

        // RN27, e para o registro pregresso também RN25: o lançamento do tutor
        // não tem aplicador, porque não houve ato clínico a atribuir a ninguém,
        // e não há profissional a quem a correção coubesse.
        abort_if(
            ! app(RetificacaoDeRegistroService::class)
                ->podeRetificarVacinacao($this->profissional, $this->prestador, $vacinacao),
            403,
            'A retificação é privativa do profissional que assinou o registro, no prestador que o produziu.',
        );

        $this->original = $vacinacao;

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(
            collect($this->only(['sitio_anatomico', 'observacao']))
                ->map(fn (mixed $valor) => is_string($valor) && trim($valor) === '' ? null : $valor)
                ->all()
        );

        if ($this->has('ordem_dose') && is_numeric($this->input('ordem_dose'))) {
            $this->merge(['ordem_dose' => (int) $this->input('ordem_dose')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo_retificacao' => ['required', 'string', 'max:2000'],

            // RN22 — a Resolução CFMV manda registrar fabricante, lote,
            // validade e via. A correção não dispensa nenhum dos quatro.
            'fabricante' => ['required', 'string', 'max:120'],
            'lote' => ['required', 'string', 'max:60'],
            'validade' => ['required', new ValidadeMesAno],
            'via_administracao' => ['required', 'string', 'max:60'],

            'sitio_anatomico' => ['nullable', 'string', 'max:120'],

            'aplicado_em' => ['required', 'date', 'before_or_equal:now'],
            'ordem_dose' => ['required', 'integer', 'min:1', 'max:20'],

            'observacao' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador) {
            $this->conferirAlteracao($validador);
            $this->conferirDataContraNascimento($validador);
        });
    }

    /**
     * A mesma regra de `RetificarAtendimentoRequest`, aplicada aos valores como
     * são exibidos: uma validade regravada no mesmo mês não é correção, é o
     * mesmo "04/2027" que já estava lá.
     */
    private function conferirAlteracao(Validator $validador): void
    {
        if ($this->original === null) {
            return;
        }

        $comparacao = app(RetificacaoDeRegistroService::class)
            ->compararVacinacoes($this->original, $this->hipotetica());

        if ($comparacao !== []) {
            return;
        }

        $validador->errors()->add(
            'lote',
            'Nenhum campo foi alterado. Uma retificação idêntica ao original acrescentaria uma versão '
            .'ao registro sem acrescentar informação alguma.',
        );
    }

    /**
     * Uma aplicação anterior ao nascimento do animal não é conduta divergente:
     * é dado impossível, e o cálculo do calendário derivaria idades negativas
     * dele (RN33). É também um dos erros que a retificação existe para corrigir
     * — e por isso a regra tem de valer aqui como vale em V07.
     */
    private function conferirDataContraNascimento(Validator $validador): void
    {
        $nascimento = $this->animal?->nascimento_em;
        $aplicadoEm = $this->input('aplicado_em');

        if ($nascimento === null || ! is_string($aplicadoEm)) {
            return;
        }

        if (Carbon::parse($aplicadoEm)->startOfDay()->gte($nascimento->copy()->startOfDay())) {
            return;
        }

        $validador->errors()->add(
            'aplicado_em',
            sprintf(
                'A aplicação não pode ser anterior ao nascimento do animal (%s).',
                $nascimento->format('d/m/Y'),
            ),
        );
    }

    /**
     * A versão que a correção produziria, montada sem gravar nada — é ela que a
     * comparação campo a campo precisa ter em mãos. O modelo não é salvo e não
     * chega perto do banco: serve de portador dos valores já convertidos, para
     * que a comparação use exatamente as mesmas regras de leitura que a
     * gravação usará depois.
     */
    private function hipotetica(): Vacinacao
    {
        // `input()` campo a campo, e não `only()`: o campo ausente do pedido
        // precisa chegar como nulo, senão a comparação leria "não enviado" como
        // "não mudou" e deixaria passar uma retificação que apaga um sítio
        // anatômico sem que nada acuse a alteração.
        return new Vacinacao([
            'fabricante' => $this->input('fabricante'),
            'lote' => $this->input('lote'),
            'via_administracao' => $this->input('via_administracao'),
            'sitio_anatomico' => $this->input('sitio_anatomico'),
            'observacao' => $this->input('observacao'),
            'ordem_dose' => $this->input('ordem_dose'),
            'validade' => ValidadeMesAno::interpretar($this->input('validade')),
            'aplicado_em' => Carbon::parse($this->input('aplicado_em')),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo_retificacao.required' => 'Escreva o motivo da retificação. Ele fica visível ao tutor ao lado '
                .'das duas versões, e é o que explica a correção a quem ler o registro depois.',
            'fabricante.required' => 'Informe o fabricante.',
            'lote.required' => 'Informe o lote.',
            'validade.required' => 'Informe a validade do lote, no formato mês/ano.',
            'via_administracao.required' => 'Informe a via de administração.',
            'aplicado_em.required' => 'Informe a data e a hora da aplicação.',
            'aplicado_em.before_or_equal' => 'A aplicação não pode estar no futuro.',
            'ordem_dose.required' => 'Informe qual dose da série esta aplicação é.',
        ];
    }
}
