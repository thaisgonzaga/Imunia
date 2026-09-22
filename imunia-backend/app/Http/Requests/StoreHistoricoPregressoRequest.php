<?php

namespace App\Http\Requests;

use App\Models\Animal;
use App\Models\User;
use App\Rules\DataAproximadaValida;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T09 — lançamento de histórico pregresso (RF29). A obrigatoriedade que a
 * migration tirou do esquema está aqui, e só aqui: RF29 pede que o tutor
 * lance "os dados de que se disponha", de modo que quase tudo é opcional.
 *
 * O que não é opcional é o registro afirmar alguma coisa. Sem vacina e sem
 * data, não há fato a guardar — e é essa a única exigência de conteúdo.
 */
class StoreHistoricoPregressoRequest extends FormRequest
{
    public ?Animal $animal = null;

    /**
     * O âmbito é resolvido aqui, e não no controlador como nas telas de
     * leitura, por causa da ordem das respostas: a validação do corpo roda
     * depois desta autorização, e um animal de outro tutor precisa responder
     * 404 antes de qualquer 422 — senão o formato do erro já contaria que o
     * pedido chegou a ser examinado.
     */
    public function authorize(): bool
    {
        /** @var User $usuario */
        $usuario = $this->user();
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        $this->animal = $tutor->animais()->where('codigo', $this->route('codigo'))->first();

        // RN12 — mesma resposta para código inexistente e animal de outro
        // tutor, como em T02, T04 e T05.
        abort_if($this->animal === null, 404, 'Animal não encontrado.');

        return true;
    }

    protected function prepareForValidation(): void
    {
        // O campo em branco e o campo ausente dizem a mesma coisa neste
        // formulário — "não sei" —, e devem chegar à validação como a mesma
        // coisa, ou `required_without` veria conteúdo onde não há.
        $this->merge(
            collect($this->only(['imunobiologico', 'data', 'local_aplicacao', 'fabricante', 'lote']))
                ->map(fn (mixed $valor) => is_string($valor) && trim($valor) === '' ? null : $valor)
                ->all()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A chave, não o id: é o identificador estável do catálogo (RF23),
            // e é o que a tela recebeu em `opcoes`.
            'imunobiologico' => [
                'nullable',
                'required_without:data',
                'string',
                Rule::exists('imunobiologicos', 'chave')->where('ativo', true),
            ],
            'data' => ['nullable', 'required_without:imunobiologico', 'string', new DataAproximadaValida],
            'local_aplicacao' => ['nullable', 'string', 'max:160'],
            'fabricante' => ['nullable', 'string', 'max:120'],
            'lote' => ['nullable', 'string', 'max:60'],

            // RF29a — a marcação de não verificado é permanente, e a tela é
            // obrigada a dizê-lo antes de salvar (`ConfirmDialog`). O aceite
            // viaja no corpo para que o servidor não dependa de a tela ter
            // cumprido a sua parte, do mesmo modo que o aceite de termos no
            // autocadastro do tutor (RF12).
            'ciente_nao_verificado' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // O mesmo texto que a tela mostra sob o botão desabilitado: quem
            // chegou aqui por outro caminho lê a mesma frase.
            'imunobiologico.required_without' => 'Informe ao menos a vacina ou a data para lançar o registro.',
            'data.required_without' => 'Informe ao menos a vacina ou a data para lançar o registro.',
            'imunobiologico.exists' => 'Esta vacina não está no catálogo. Escolha uma da lista ou "não sei informar".',
            'ciente_nao_verificado.accepted' => 'É preciso confirmar que o registro entra marcado como não verificado.',
        ];
    }
}
