<?php

namespace App\Http\Requests;

use App\Models\Tutor;
use App\Models\User;
use App\Support\DataAproximada;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T03 — cadastrar animal (RF16, RN13, RN17).
 *
 * O tutor identifica o seu animal; quem o caracteriza é o veterinário (RF19).
 * A divisão está desenhada na tela, mas é aqui que ela vale: RF16e manda o
 * servidor recusar o campo privativo submetido pelo tutor, e não apenas
 * escondê-lo do formulário. Esconder é conveniência; recusar é o controle.
 */
class StoreAnimalRequest extends FormRequest
{
    public ?Tutor $tutor = null;

    /**
     * Campos que só o veterinário preenche (RF19, RN18), mais os que são do
     * sistema (`codigo`, RF17) e os que decorrem de outro ato (`obito_em`,
     * RF22). Chegando qualquer um deles, o pedido é recusado inteiro — o
     * cadastro não é criado "menos aquele campo", porque quem o enviou precisa
     * saber que ele não foi aceito.
     *
     * `nascimento_exato` está na lista pela mesma razão, e é a mais sutil
     * delas: o tutor pode informar a data (RF16), mas quem afirma que ela é
     * exata é o veterinário (RN14). Aceitá-la aqui deixaria o tutor promover a
     * própria estimativa a aferição.
     */
    private const PRIVATIVOS_DO_VETERINARIO = [
        'raca',
        'pelagem',
        'peso',
        'situacao_reprodutiva',
        'microchip',
        'nascimento_exato',
        'caracterizado_em',
        'obito_em',
        'codigo',
        'tutor_id',
    ];

    public function authorize(): bool
    {
        /** @var User $usuario */
        $usuario = $this->user();
        $this->tutor = $usuario->tutor;

        abort_if($this->tutor === null, 403, 'Esta área é do ambiente do tutor.');

        return true;
    }

    protected function prepareForValidation(): void
    {
        // Os opcionais vêm de um bloco recolhido na tela: em branco e ausente
        // dizem a mesma coisa — "não sei" —, e precisam chegar iguais à
        // validação, ou `nullable` veria conteúdo onde há string vazia.
        $this->merge(
            collect($this->only(['nome', 'sexo', 'nascimento']))
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
            'nome' => ['required', 'string', 'max:60'],

            // RN13 — o sistema atende cão e gato, e nada além disso. A recusa
            // é do servidor porque a tela oferece dois cartões e mais nada:
            // uma terceira espécie só chega aqui por fora dela.
            'especie' => ['required', Rule::in(['cao', 'gato'])],

            'sexo' => ['nullable', Rule::in(['macho', 'femea'])],

            // O mesmo formato do histórico pregresso (T09): ano, ou mês e ano.
            // O campo se chama "nascimento estimado" na tela, e aceitar o dia
            // daria à declaração uma precisão que o próprio rótulo nega — a
            // confirmação da data é ato do veterinário (RF19, RN14).
            'nascimento' => ['nullable', 'string', function (string $atributo, mixed $valor, Closure $falhar): void {
                if (! DataAproximada::reconhece((string) $valor)) {
                    $falhar('Escreva o ano (2024) ou o mês e o ano (06/2024).');

                    return;
                }

                if (DataAproximada::noFuturo((string) $valor)) {
                    $falhar('A data de nascimento precisa ser passada.');
                }
            }],

            // RF20a — o alerta de duplicidade precede a confirmação, e é esta
            // a confirmação: o segundo envio, com o tutor já ciente de qual
            // cadastro se parece com o que ele está criando.
            'confirmar_duplicidade' => ['sometimes', 'boolean'],

            ...array_fill_keys(self::PRIVATIVOS_DO_VETERINARIO, ['prohibited']),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome do animal.',
            'especie.required' => 'Escolha se é um cão ou um gato.',
            'especie.in' => 'O Imunia atende cães e gatos.',

            ...array_fill_keys(
                array_map(fn (string $campo) => "{$campo}.prohibited", self::PRIVATIVOS_DO_VETERINARIO),
                'Este dado é preenchido pelo veterinário na consulta, e não no cadastro.',
            ),
        ];
    }

    /**
     * A data como a coluna a guarda: o primeiro dia do período declarado. A
     * imprecisão não se perde — ela viaja em `nascimento_exato`, que continua
     * falso até o veterinário confirmar (RN14).
     */
    public function nascimentoEm(): ?string
    {
        $declarado = $this->validated('nascimento');

        if ($declarado === null) {
            return null;
        }

        return DataAproximada::interpretar($declarado)?->toDateString();
    }
}
