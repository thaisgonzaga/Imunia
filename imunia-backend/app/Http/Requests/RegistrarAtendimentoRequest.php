<?php

namespace App\Http\Requests;

use App\Http\Controllers\Concerns\ResolvePrestadorAtivo;
use App\Models\Animal;
use App\Models\Prestador;
use App\Models\User;
use App\Services\RegistroDeAtendimentoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * V08 — registro do prontuário do atendimento (RF31, RF32, RF34).
 *
 * A obrigatoriedade é a do esquema, e o esquema é o da Resolução CFMV
 * nº 1.321/2020: motivo, anamnese, exame físico, hipóteses, conduta. O
 * diagnóstico é o único que admite silêncio, e por uma razão clínica — a
 * consulta pode encerrar-se com ele pendente de exame, e afirmar um diagnóstico
 * que não existe seria pior do que declarar a pendência.
 *
 * O âmbito é resolvido em `authorize()`, e não no controlador, pela mesma ordem
 * de respostas de V07: um animal sem autorização vigente precisa responder 403
 * antes de qualquer 422, senão o formato do erro já contaria que o prontuário
 * chegou a ser examinado.
 */
class RegistrarAtendimentoRequest extends FormRequest
{
    use ResolvePrestadorAtivo;

    public ?User $profissional = null;

    public ?Prestador $prestador = null;

    public ?Animal $animal = null;

    public function authorize(): bool
    {
        [$this->profissional, $this->prestador] = $this->contextoClinico($this);

        // RN21 — só médico-veterinário com CRMV registrado cria registro clínico.
        $this->crmvExigido($this->profissional, $this->prestador);

        $this->animal = Animal::query()->where('codigo', $this->route('codigo'))->first();

        abort_if($this->animal === null, 404, 'Animal não encontrado.');

        // RF35c, RN37 — sem autorização vigente do tutor não há prontuário a
        // escrever. A recusa nomeia o caminho (V10): a resposta certa para o
        // profissional aqui não é desistir, é pedir acesso.
        abort_if(
            ! $this->animal->autorizacoes()->where('prestador_id', $this->prestador->id)->vigente()->exists(),
            403,
            'Este animal não está sob autorização vigente do tutor. Solicite o acesso antes de registrar.',
        );

        // RF22a — registrado o óbito, encerram-se os registros clínicos do
        // animal. A única escrita que resta é a retificação (RF33).
        abort_if(
            $this->animal->inativo(),
            403,
            'Este animal tem óbito registrado. Não há novo atendimento a lançar.',
        );

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(
            collect($this->only(['diagnostico', 'retorno_finalidade']))
                ->map(fn (mixed $valor) => is_string($valor) && trim($valor) === '' ? null : $valor)
                ->all()
        );

        // A balança do consultório escreve "12,4", e o campo da tela guarda o
        // que foi digitado. A conversão é aqui para que a regra `numeric` veja
        // o número que o profissional quis dizer, e não um erro de formato.
        if (is_string($this->input('peso_kg'))) {
            $peso = str_replace(',', '.', trim($this->input('peso_kg')));

            $this->merge(['peso_kg' => $peso === '' ? null : $peso]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // RF31 — o conteúdo clínico, na ordem em que o prontuário é lido.
            'motivo' => ['required', 'string', 'max:2000'],
            'anamnese' => ['required', 'string', 'max:5000'],
            'exame_fisico' => ['required', 'string', 'max:5000'],
            'hipoteses_diagnosticas' => ['required', 'string', 'max:2000'],
            'diagnostico' => ['nullable', 'string', 'max:2000'],
            'conduta' => ['required', 'string', 'max:5000'],

            // Medição datada, e não campo de cadastro: opcional porque nem toda
            // consulta passa pela balança, e limitada ao que existe em cão e
            // gato — um "1240" digitado sem vírgula é erro de digitação, não
            // paciente.
            'peso_kg' => ['nullable', 'numeric', 'min:0.1', 'max:200'],

            // RF34 — data prevista **e** finalidade descrita. Uma sem a outra
            // não é retorno programado: é data sem propósito ou propósito sem
            // data, e nenhum dos dois alimenta o lembrete de RF43.
            'retorno_em' => ['nullable', 'required_with:retorno_finalidade', 'date', 'after_or_equal:today'],
            'retorno_finalidade' => ['nullable', 'required_with:retorno_em', 'string', 'max:160'],

            'anexos' => ['array', 'max:10'],
            'anexos.*.token' => ['required', 'uuid'],

            // RF32 — descrição e data do exame por arquivo. A descrição é
            // obrigatória porque é ela que nomeia o documento em T08 e no
            // download; a data, não, porque nem todo documento tem data própria.
            'anexos.*.descricao' => ['required', 'string', 'max:160'],
            'anexos.*.exame_em' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador) {
            $this->conferirAnexosEnviados($validador);
        });
    }

    /**
     * O anexo confirmado precisa ter chegado ao servidor e continuar lá. Falha
     * quando o rascunho venceu ou foi descartado noutra aba — e a mensagem diz
     * o que fazer, porque o texto do prontuário continua no formulário e o
     * profissional não vai reescrevê-lo por causa de um arquivo.
     */
    private function conferirAnexosEnviados(Validator $validador): void
    {
        $servico = app(RegistroDeAtendimentoService::class);

        foreach ((array) $this->input('anexos', []) as $indice => $anexo) {
            $token = is_array($anexo) ? ($anexo['token'] ?? null) : null;

            if (! is_string($token) || $this->profissional === null) {
                continue;
            }

            if ($servico->caminhoDoRascunho($this->profissional, $token) === null) {
                $validador->errors()->add(
                    "anexos.{$indice}.token",
                    'Este arquivo não está mais disponível para anexar. Envie-o novamente — o texto do atendimento continua aqui.',
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Descreva o motivo da consulta.',
            'anamnese.required' => 'A anamnese é obrigatória no prontuário.',
            'exame_fisico.required' => 'Descreva os achados do exame físico.',
            'hipoteses_diagnosticas.required' => 'Informe ao menos uma hipótese diagnóstica.',
            'conduta.required' => 'Descreva a conduta terapêutica adotada.',
            'peso_kg.numeric' => 'Informe o peso em quilogramas, como 12,4.',
            'retorno_em.after_or_equal' => 'A data do retorno não pode ser anterior a hoje.',
            'retorno_em.required_with' => 'Informe a data do retorno, ou apague a finalidade.',
            'retorno_finalidade.required_with' => 'Descreva a finalidade do retorno — é ela que vai no lembrete ao tutor.',
            'anexos.*.descricao.required' => 'Descreva o que é este arquivo. A descrição nomeia o documento no prontuário.',
        ];
    }
}
