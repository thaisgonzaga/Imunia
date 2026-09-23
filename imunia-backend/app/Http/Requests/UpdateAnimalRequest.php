<?php

namespace App\Http\Requests;

use App\Models\Animal;
use App\Models\Tutor;
use App\Models\User;
use App\Support\DataAproximada;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * T04a — editar a identificação do animal (RF16, RN18, RF19d).
 *
 * A contrapartida de T03: o que o tutor pôde escrever no cadastro, ele pode
 * corrigir depois — nome, espécie e foto são identificação e permanecem dele
 * (RF16), e a fotografia, por RN20, é mantida pelo próprio tutor a qualquer
 * tempo. O que ele nunca escreve continua o mesmo, e a lista de proibidos é a
 * de T03, palavra por palavra: esconder o campo é conveniência, recusá-lo é o
 * controle (RF16e).
 *
 * Duas fronteiras são desta tela, e não do cadastro:
 *
 * - a espécie trava no primeiro registro clínico (RF19d), porque protocolo e
 *   calendário já foram calculados para a que constava ali;
 * - sexo e nascimento deixam de ser do tutor quando o veterinário caracteriza o
 *   animal (RF19b, RN18). Até lá são declaração dele e ele os corrige; depois,
 *   são aferição profissional, e reescrevê-los pela tela do tutor desfaria a
 *   confirmação que dá valor documental à ficha.
 */
class UpdateAnimalRequest extends FormRequest
{
    public ?Animal $animal = null;

    /**
     * A mesma lista de `StoreAnimalRequest`, e pela mesma razão. `codigo` está
     * nela porque RF17b não abre exceção para a edição: o identificador não
     * muda em circunstância alguma prevista no sistema.
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

    /**
     * A busca parte do tutor autenticado, como em toda rota de T04: um código
     * que existe mas é de outro tutor responde 404, igual a um que nunca
     * existiu (RN12).
     */
    public function authorize(): bool
    {
        /** @var User $usuario */
        $usuario = $this->user();

        /** @var Tutor|null $tutor */
        $tutor = $usuario->tutor;

        abort_if($tutor === null, 403, 'Esta área é do ambiente do tutor.');

        $this->animal = $tutor->animais()->where('codigo', $this->route('codigo'))->first();

        abort_if($this->animal === null, 404, 'Animal não encontrado.');

        return true;
    }

    protected function prepareForValidation(): void
    {
        // Como em T03: em branco e ausente dizem a mesma coisa — "não sei" — e
        // precisam chegar iguais à validação.
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

            'especie' => [
                'required',
                Rule::in(['cao', 'gato']), // RN13

                // RF19d — não é "campo somente leitura": a espécie continua
                // exigida e continua conferida. O que se recusa é a troca, e só
                // quando já existe registro clínico contando com a atual.
                function (string $atributo, mixed $valor, Closure $falhar): void {
                    if ($valor !== $this->animal->especie && $this->animal->possuiRegistroClinico()) {
                        $falhar(
                            'A espécie não muda depois que há registro clínico no histórico. '
                            .'Fale com o veterinário que atende seu animal.'
                        );
                    }
                },
            ],

            ...$this->regrasDoQueOTutorDeclara(),
            ...array_fill_keys(self::PRIVATIVOS_DO_VETERINARIO, ['prohibited']),
        ];
    }

    /**
     * Sexo e nascimento enquanto o cadastro é preliminar (RN17); depois da
     * caracterização, proibidos como os demais campos do veterinário.
     *
     * `prohibited` admite o campo ausente e o campo vazio, e é o que faz a tela
     * poder enviar sempre o mesmo corpo: o formulário que não mostra o campo
     * manda `null`, e null não é tentativa de escrita.
     *
     * @return array<string, mixed>
     */
    private function regrasDoQueOTutorDeclara(): array
    {
        if (! $this->animal->preliminar()) {
            return [
                'sexo' => ['prohibited'],
                'nascimento' => ['prohibited'],
            ];
        }

        return [
            'sexo' => ['nullable', Rule::in(['macho', 'femea'])],

            // O mesmo formato de T03 e T09: ano, ou mês e ano. O dia não entra
            // porque a confirmação da data é ato do veterinário (RF19, RN14).
            'nascimento' => ['nullable', 'string', function (string $atributo, mixed $valor, Closure $falhar): void {
                if (! DataAproximada::reconhece((string) $valor)) {
                    $falhar('Escreva o ano (2024) ou o mês e o ano (06/2024).');

                    return;
                }

                if (DataAproximada::noFuturo((string) $valor)) {
                    $falhar('A data de nascimento precisa ser passada.');
                }
            }],
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

            // Depois da consulta, o dado declarado virou dado confirmado, e a
            // frase precisa dizer isso — não "você não pode".
            'sexo.prohibited' => 'O veterinário já confirmou este dado na consulta. '
                .'A partir daí, quem o mantém é ele.',
            'nascimento.prohibited' => 'O veterinário já confirmou este dado na consulta. '
                .'A partir daí, quem o mantém é ele.',

            ...array_fill_keys(
                array_map(fn (string $campo) => "{$campo}.prohibited", self::PRIVATIVOS_DO_VETERINARIO),
                'Este dado é preenchido pelo veterinário na consulta, e não por você.',
            ),
        ];
    }

    /**
     * A data como a coluna a guarda — o primeiro dia do período declarado —, ou
     * nulo quando o tutor apagou o que havia declarado.
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
