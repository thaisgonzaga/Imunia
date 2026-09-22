<?php

namespace App\Http\Requests;

use App\Models\ProtocoloVacinal;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * X02 — parâmetros temporais de um imunobiológico dentro de um rascunho
 * (RF24). As regras aqui são de sanidade de cada campo isolado, e só disso:
 * a contradição entre dois parâmetros — intervalo maior que o prazo do reforço,
 * por exemplo — não invalida a gravação.
 *
 * A distinção é do desenho da tela e importa: o rascunho continua salvo e
 * editável enquanto os valores se contradisserem; o que fica indisponível é a
 * publicação, porque versão publicada é usada em cálculo real. Impedir a
 * gravação obrigaria a pessoa a acertar dois campos numa tacada só.
 */
class SalvarParametrosProtocoloRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $usuario */
        $usuario = $this->user();

        abort_if(! $usuario->admin_plataforma, 403, 'Esta área é da administração da plataforma.');

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Só o acervo da plataforma. A versão publicada é diretriz, e o
            // item próprio de uma clínica não entra numa: ele não se publica,
            // não se encerra, e a linha dele vive com `versao_protocolo_id`
            // nulo justamente para que a publicação seguinte não o alcance.
            'imunobiologico_id' => ['required', 'integer', Rule::exists('imunobiologicos', 'id')->whereNull('prestador_id')],
            'numero_doses_serie_primaria' => ['required', 'integer', 'min:1', 'max:10'],
            'intervalo_minimo_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'intervalo_maximo_dias' => ['required', 'integer', 'min:0', 'max:730'],
            'idade_minima_primeira_dose_semanas' => ['nullable', 'integer', 'min:0', 'max:104'],
            'idade_minima_dose_final_semanas' => ['nullable', 'integer', 'min:0', 'max:104'],
            'reforco_inicial_meses' => ['nullable', 'integer', 'min:1', 'max:120'],
            'periodicidade_revacinacao_meses' => ['required', 'integer', 'min:1', 'max:120'],
            'limite_atraso_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'conduta_apos_limite' => ['required', 'in:'.ProtocoloVacinal::PROSSEGUIR.','.ProtocoloVacinal::REINICIAR],
            'doses_adulto_sem_historico' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'imunobiologico_id' => 'imunobiológico',
            'numero_doses_serie_primaria' => 'doses da série primária',
            'intervalo_minimo_dias' => 'intervalo mínimo entre doses',
            'intervalo_maximo_dias' => 'intervalo máximo entre doses',
            'idade_minima_primeira_dose_semanas' => 'idade mínima da primeira dose',
            'idade_minima_dose_final_semanas' => 'idade mínima da dose final',
            'reforco_inicial_meses' => 'prazo do primeiro reforço',
            'periodicidade_revacinacao_meses' => 'periodicidade da revacinação',
            'limite_atraso_dias' => 'limite de atraso',
            'conduta_apos_limite' => 'conduta sugerida acima do limite',
            'doses_adulto_sem_historico' => 'doses no adulto sem histórico',
        ];
    }
}
