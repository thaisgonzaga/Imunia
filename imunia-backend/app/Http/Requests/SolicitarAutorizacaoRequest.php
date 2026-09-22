<?php

namespace App\Http\Requests;

use App\Support\TermoDeBusca;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * O pedido de autorização do ambiente clínico (V10, RF38).
 *
 * O alvo chega como termo — o mesmo vocabulário da busca de V03, de onde o
 * pedido nasce —, e não como identificador interno: a resposta da busca não
 * entrega id de tutor nem de animal a quem não tem autorização (RN12), e o
 * pedido não pode exigir da tela um dado que a tela nunca recebeu.
 */
class SolicitarAutorizacaoRequest extends FormRequest
{
    private ?TermoDeBusca $termo = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['termo' => trim((string) $this->input('termo'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'termo' => [
                'required',
                'string',
                'max:120',

                /**
                 * Só as chaves exatas identificam a quem se pede. Pelo nome, a
                 * correspondência é um conjunto indeterminado de titulares —
                 * pedir "a todos que se chamam Helena" seria transformar o
                 * pedido em varredura, e o cartão coletivo de V03 não oferece
                 * este caminho.
                 */
                function (string $atributo, mixed $valor, Closure $falhar): void {
                    if (! $this->termo()->chaveExata()) {
                        $falhar('Para solicitar autorização, busque pelo CPF do tutor, pelo código do animal ou pelo micro-chip.');

                        return;
                    }

                    if (! $this->termo()->cpfConfere()) {
                        $falhar('Este CPF não é válido: o dígito verificador não confere. Confira o número com o tutor.');
                    }
                },
            ],

            // V10 — uma linha de contexto, opcional. O tamanho é o da coluna.
            'mensagem' => ['nullable', 'string', 'max:280'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'termo.required' => 'Informe o CPF do tutor, o código do animal ou o micro-chip.',
            'termo.max' => 'O termo de busca é longo demais.',
            'mensagem.max' => 'A mensagem pode ter no máximo 280 caracteres.',
        ];
    }

    public function termo(): TermoDeBusca
    {
        return $this->termo ??= TermoDeBusca::de((string) $this->input('termo'));
    }

    public function mensagem(): ?string
    {
        $mensagem = trim((string) $this->input('mensagem'));

        return $mensagem === '' ? null : $mensagem;
    }
}
