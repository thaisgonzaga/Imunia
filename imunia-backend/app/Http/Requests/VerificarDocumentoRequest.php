<?php

namespace App\Http\Requests;

use App\Models\Exportacao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class VerificarDocumentoRequest extends FormRequest
{
    /**
     * Verificações toleradas antes da pausa. Um conferente legítimo tem um
     * documento na mão, não uma lista deles.
     */
    private const VERIFICACOES = 10;

    /**
     * Duração da pausa, em segundos. A tela exibe a contagem regressiva (P09).
     */
    private const PAUSA = 2 * 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * O código chega pela rota, e o resumo — quando chega — vem do QR Code
     * impresso. Os dois são normalizados antes da validação, que passa então a
     * julgar só o que interessa: o comprimento.
     */
    protected function prepareForValidation(): void
    {
        $resumo = Str::of($this->query('resumo') ?? '')
            ->upper()
            ->replaceMatches('/[^0-9A-F]/', '')
            ->toString();

        $this->merge([
            'codigo' => Exportacao::normalizarCodigo($this->route('codigo')),
            'resumo' => $resumo === '' ? null : $resumo,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'size:'.Exportacao::COMPRIMENTO_CODIGO],
            'resumo' => ['nullable', 'string', 'size:'.Exportacao::COMPRIMENTO_RESUMO_ABREVIADO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'Informe o código impresso no rodapé do documento.',
            'codigo.size' => 'O código tem 16 caracteres, em quatro grupos de quatro.',
            'resumo.size' => 'O resumo do documento tem 16 caracteres.',
        ];
    }

    /**
     * A pausa é contada por origem, e não por código: se contasse por código,
     * bastaria variar o código a cada tentativa para percorrer a base à vontade
     * — que é exatamente a enumeração de que RF47c fala. Pelo mesmo motivo ela
     * incide antes de saber se o documento existe.
     */
    protected function passedValidation(): void
    {
        $chave = 'verificar-documento:'.$this->ip();

        if (RateLimiter::tooManyAttempts($chave, self::VERIFICACOES)) {
            abort(response()->json([
                'message' => 'Muitas verificações seguidas. Aguarde para verificar outro documento.',
                'segundos_restantes' => RateLimiter::availableIn($chave),
            ], 429));
        }

        RateLimiter::hit($chave, self::PAUSA);
    }

    public function codigo(): string
    {
        return $this->validated('codigo');
    }

    public function resumo(): ?string
    {
        return $this->validated('resumo');
    }
}
