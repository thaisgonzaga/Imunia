<?php

namespace App\Http\Requests;

use App\Support\TermoDeBusca;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * O termo da busca do ambiente clínico (V03, RF51).
 *
 * As telas de painel do veterinário saneiam os próprios filtros no controlador,
 * sem Form Request, porque valor fora da lista volta ao padrão em vez de virar
 * erro. Aqui é o contrário, e de propósito: o CPF com dígito verificador
 * incorreto é um estado previsto da tela (§6.1 do briefing), e precisa voltar
 * como erro de campo — nunca como busca que não achou nada, que faria o
 * profissional procurar o cadastro do tutor em vez de conferir o número.
 */
class BuscarNaClinicaRequest extends FormRequest
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
                // Sem termo, a rota devolve só o contexto clínico — prestador
                // ativo e vínculos —, que é o estado inicial da tela: campo
                // vazio com os formatos aceitos. Exigir o termo obrigaria a
                // primeira pintura da tela a uma consulta que ela não quer
                // fazer, e o desenho depende de saber o prestador ativo antes
                // de qualquer busca.
                'nullable',
                'string',
                'max:120',

                /**
                 * RF13 — a conferência do dígito acontece antes de qualquer
                 * consulta, e é o que sustenta a promessa que a tela faz nesse
                 * estado: nada foi consultado e nada foi registrado. Um número
                 * digitado errado é o CPF de outra pessoa, e não pode produzir
                 * registro de acesso no nome dela.
                 */
                function (string $atributo, mixed $valor, Closure $falhar): void {
                    if (! $this->termo()->cpfConfere()) {
                        $falhar('Este CPF não é válido: o dígito verificador não confere. Confira o número com o tutor.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'termo.max' => 'O termo de busca é longo demais.',
        ];
    }

    public function termo(): TermoDeBusca
    {
        return $this->termo ??= TermoDeBusca::de((string) $this->input('termo'));
    }
}
