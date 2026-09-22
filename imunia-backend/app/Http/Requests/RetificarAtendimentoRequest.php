<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Models\Animal;
use App\Models\Atendimento;
use App\Models\Prestador;
use App\Models\User;
use App\Services\AtendimentoService;
use App\Services\RetificacaoDeRegistroService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * V09 — retificação do prontuário do atendimento (RF33, RN26, RN27).
 *
 * A ordem das recusas em `authorize()` é a mesma de V07 e V08, e pelo mesmo
 * motivo: âmbito antes de conteúdo. Um 422 sobre um registro que o profissional
 * não pode retificar já teria contado que o registro existe e o que ele contém.
 *
 * RNF09 governa o par tela/servidor aqui: a ação não aparece na interface para
 * quem não é o autor, e a recusa é ainda assim do servidor. As duas coisas ao
 * mesmo tempo — a interface não oferece o que não cabe, e não é a interface que
 * decide o que cabe.
 */
class RetificarAtendimentoRequest extends FormRequest
{
    use ResolvePrestadorAtivo;

    public ?User $profissional = null;

    public ?Prestador $prestador = null;

    public ?Animal $animal = null;

    public ?Atendimento $original = null;

    public function authorize(): bool
    {
        [$this->profissional, $this->prestador] = $this->contextoClinico($this);

        // RN21 — a retificação é ato clínico como o registro que corrige, e
        // exige a mesma inscrição.
        $this->crmvExigido($this->profissional, $this->prestador);

        $this->animal = Animal::query()->where('codigo', $this->route('codigo'))->first();

        abort_if($this->animal === null, 404, 'Animal não encontrado.');

        /** @var Atendimento|null $atendimento */
        $atendimento = $this->animal->atendimentos()->find($this->route('atendimento'));

        abort_if($atendimento === null, 404, 'Atendimento não encontrado.');

        // RN48 — sem autorização vigente do tutor não há registro a ler nem a
        // corrigir. A autoria não dispensa o consentimento: o vínculo que
        // permite ao prestador acompanhar este animal é do tutor, e pode ter
        // sido revogado desde a consulta (RF39).
        abort_if(
            ! $this->animal->autorizacoes()->where('prestador_id', $this->prestador->id)->vigente()->exists(),
            403,
            'Este animal não está sob autorização vigente do tutor. Solicite o acesso antes de retificar.',
        );

        // RN27 — e a recusa não diz mais do que precisa. Que o registro existe,
        // quem o assinou e por que a ação não cabe são respostas que a tela do
        // autor já dá a quem é autor.
        abort_if(
            ! app(RetificacaoDeRegistroService::class)
                ->podeRetificarAtendimento($this->profissional, $this->prestador, $atendimento),
            403,
            'A retificação é privativa do profissional que assinou o registro, no prestador que o produziu.',
        );

        $this->original = $atendimento;

        return true;
    }

    protected function prepareForValidation(): void
    {
        // O único campo do prontuário que admite silêncio (RF31): uma caixa
        // esvaziada é o diagnóstico que deixou de ser afirmado, não a cadeia
        // vazia.
        if (is_string($this->input('diagnostico')) && trim($this->input('diagnostico')) === '') {
            $this->merge(['diagnostico' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // RF33 — o motivo é obrigatório, e não é formalidade: é ele que
            // explica a correção a quem ler o prontuário depois, e é ele que o
            // tutor vê ao lado das duas versões.
            'motivo_retificacao' => ['required', 'string', 'max:2000'],

            // As mesmas regras de V08. A correção não afrouxa a exigência do
            // registro: um prontuário retificado continua sendo prontuário, e a
            // Resolução CFMV nº 1.321/2020 não conhece versão preliminar.
            'motivo' => ['required', 'string', 'max:2000'],
            'anamnese' => ['required', 'string', 'max:5000'],
            'exame_fisico' => ['required', 'string', 'max:5000'],
            'hipoteses_diagnosticas' => ['required', 'string', 'max:2000'],
            'diagnostico' => ['nullable', 'string', 'max:2000'],
            'conduta' => ['required', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(fn (Validator $validador) => $this->conferirAlteracao($validador));
    }

    /**
     * Uma retificação sem alteração criaria uma segunda versão idêntica no
     * prontuário, sem informação nova, e faria o histórico anunciar uma
     * correção que não corrigiu nada. A tela desabilita o botão até que algo
     * mude; esta é a mesma regra do lado que não depende de a tela ter
     * obedecido.
     */
    private function conferirAlteracao(Validator $validador): void
    {
        if ($this->original === null) {
            return;
        }

        $mudou = collect(AtendimentoService::SECOES)->keys()->contains(
            fn (string $chave) => $this->input($chave) !== $this->original->{$chave},
        );

        if ($mudou) {
            return;
        }

        $validador->errors()->add(
            'motivo',
            'Nenhum campo foi alterado. Uma retificação idêntica ao original acrescentaria uma versão '
            .'ao prontuário sem acrescentar informação alguma.',
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo_retificacao.required' => 'Escreva o motivo da retificação. Ele fica visível ao tutor ao lado '
                .'das duas versões, e é o que explica a correção a quem ler o prontuário depois.',
            'motivo.required' => 'Descreva o motivo da consulta.',
            'anamnese.required' => 'A anamnese é obrigatória no prontuário.',
            'exame_fisico.required' => 'Descreva os achados do exame físico.',
            'hipoteses_diagnosticas.required' => 'Informe ao menos uma hipótese diagnóstica.',
            'conduta.required' => 'Descreva a conduta terapêutica adotada.',
        ];
    }
}
