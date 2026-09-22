<?php

namespace App\Http\Requests\Concerns;

use App\Support\DataAproximada;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

/**
 * As regras da caracterização do animal (RF19, RN18), compartilhadas entre o
 * cadastro de V05 e a consolidação de RF20: os campos são os mesmos e o
 * vocabulário de erro tem de ser o mesmo, porque para quem preenche é a mesma
 * metade da tela.
 *
 * A data de nascimento tem aqui uma forma a mais do que em T03: o veterinário
 * pode afirmá-la exata (RN14), e aí ela vem completa — dia, mês e ano. A
 * estimativa continua sendo ano ou mês/ano, como a do tutor: marcar "exata" é
 * o que muda a exigência, e não o contrário.
 */
trait ValidaCaracterizacaoDoAnimal
{
    /**
     * @return array<string, mixed>
     */
    protected function regrasDeCaracterizacao(): array
    {
        return [
            'sexo' => ['nullable', Rule::in(['macho', 'femea'])],

            'nascimento' => ['nullable', 'string', function (string $atributo, mixed $valor, Closure $falhar): void {
                if ($this->boolean('nascimento_exato')) {
                    if ($this->dataCompleta((string) $valor) === null) {
                        $falhar('Com a data marcada como exata, escreva dia, mês e ano (04/06/2024).');

                        return;
                    }

                    if ($this->dataCompleta((string) $valor)->isAfter(CarbonImmutable::today())) {
                        $falhar('A data de nascimento precisa ser passada.');
                    }

                    return;
                }

                if (! DataAproximada::reconhece((string) $valor)) {
                    $falhar('Escreva o ano (2024) ou o mês e o ano (06/2024) — ou marque a data como exata e informe o dia.');

                    return;
                }

                if (DataAproximada::noFuturo((string) $valor)) {
                    $falhar('A data de nascimento precisa ser passada.');
                }
            }],

            // RN14 — só o veterinário afirma exatidão, e só junto da data que
            // está afirmando: exata sem data não afirma nada.
            'nascimento_exato' => ['sometimes', 'boolean', function (string $atributo, mixed $valor, Closure $falhar): void {
                if ($this->boolean('nascimento_exato') && trim((string) $this->input('nascimento')) === '') {
                    $falhar('Marque a data como exata somente quando informar a data de nascimento.');
                }
            }],

            'raca' => ['nullable', 'string', 'max:80'],
            'pelagem' => ['nullable', 'string', 'max:60'],
            'situacao_reprodutiva' => ['nullable', Rule::in(['inteiro', 'castrado'])],

            // ISO 11784/11785 — quinze dígitos, como a busca de V03 já espera.
            'microchip' => ['nullable', 'digits:15'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensagensDeCaracterizacao(): array
    {
        return [
            'sexo.in' => 'Escolha entre macho e fêmea.',
            'situacao_reprodutiva.in' => 'Escolha entre inteiro e castrado.',
            'microchip.digits' => 'O micro-chip tem 15 dígitos. Confira o número no leitor.',
        ];
    }

    /**
     * Os campos em branco chegam como nulo, para que `nullable` não veja
     * conteúdo onde há string vazia — o mesmo saneamento de T03.
     */
    protected function sanearCaracterizacao(): void
    {
        $this->merge(
            collect($this->only(['sexo', 'nascimento', 'raca', 'pelagem', 'situacao_reprodutiva', 'microchip']))
                ->map(fn (mixed $valor) => is_string($valor) && trim($valor) === '' ? null : $valor)
                ->all()
        );
    }

    /**
     * A data como a coluna a guarda. Exata, é o próprio dia; estimada, o
     * primeiro dia do período declarado, com a imprecisão viajando em
     * `nascimento_exato` (RN14) — nunca no dia gravado.
     */
    public function nascimentoEm(): ?string
    {
        $declarado = $this->validated('nascimento');

        if ($declarado === null) {
            return null;
        }

        if ($this->nascimentoExato()) {
            return $this->dataCompleta($declarado)?->toDateString();
        }

        return DataAproximada::interpretar($declarado)?->toDateString();
    }

    public function nascimentoExato(): bool
    {
        return $this->boolean('nascimento_exato') && $this->validated('nascimento') !== null;
    }

    /**
     * `dd/mm/aaaa`, conferido de verdade: 31/02 não é data em nenhum ano.
     */
    private function dataCompleta(string $valor): ?CarbonImmutable
    {
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/((?:19|20)\d{2})$/', trim($valor), $partes) !== 1) {
            return null;
        }

        if (! checkdate((int) $partes[2], (int) $partes[1], (int) $partes[3])) {
            return null;
        }

        return CarbonImmutable::create((int) $partes[3], (int) $partes[2], (int) $partes[1]);
    }
}
