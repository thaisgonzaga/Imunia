<?php

namespace App\Support;

use App\Models\RegistroDeAcesso;
use App\Rules\CpfValido;

/**
 * O termo digitado na busca do ambiente clínico (V03, RF51), classificado pelo
 * que ele é: CPF do tutor, código do animal, micro-chip ou nome.
 *
 * A tela tem um campo só, e a detecção é automática (§8.3 do briefing) — pedir
 * ao profissional que declare o tipo antes de digitar seria transformar em
 * pergunta o que o próprio formato do dado já responde. A classificação vive
 * aqui, e não na tela nem no serviço, porque três lugares precisam concordar
 * sobre ela: a validação que recusa o CPF inválido, a consulta que escolhe a
 * coluna, e o registro de acesso que diz ao tutor por qual chave o procuraram.
 */
final class TermoDeBusca
{
    public const CPF = 'cpf';

    public const CODIGO = 'codigo';

    public const MICROCHIP = 'microchip';

    public const NOME = 'nome';

    /** ISO 11784/11785 — o transponder tem quinze dígitos. */
    private const DIGITOS_DO_MICROCHIP = 15;

    /**
     * Abaixo disto, o nome não revela existência de cadastro alheio. Ver
     * `podeRevelarExistencia()`.
     */
    private const MINIMO_PARA_REVELAR_EXISTENCIA = 3;

    private function __construct(
        /** Um dos quatro tipos acima. */
        public readonly string $tipo,
        /** Como foi digitado — é o que a tela repete no título do resultado. */
        public readonly string $original,
        /** Como o banco guarda: CPF em dígitos, código com hifens, nome como veio. */
        public readonly string $valor,
    ) {
    }

    public static function de(?string $termo): self
    {
        $limpo = trim(preg_replace('/\s+/', ' ', (string) $termo));

        // Maiúsculas e sem pontuação: é assim que o código do animal se
        // compara, e é assim que "im-4b8t 77lx" vira o que está no banco.
        $compacto = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $limpo));
        $digitos = preg_replace('/\D/', '', $limpo);

        // Reconhecido pela forma — IM e mais oito —, e não pelo alfabeto de
        // CodigoDoAnimal, que exclui 0, 1, I e O. A diferença importa no erro:
        // quem transcreve do papel troca justamente O por 0, e exigir o
        // alfabeto faria esse engano cair na busca por nome, onde a tela diria
        // "nenhum resultado" em vez de "confira os caracteres do código".
        // Classificar é reconhecer a intenção, não validar.
        //
        // O hífen ou o dígito na forma digitada é o que separa um código de um
        // nome próprio de dez letras começado por "Im": no papel o código vem
        // com hifens, e sem eles quase sempre traz algarismo.
        if (preg_match('/^IM([0-9A-Z]{4})([0-9A-Z]{4})$/', $compacto, $blocos) && preg_match('/[\d-]/', $limpo)) {
            return new self(self::CODIGO, $limpo, sprintf('IM-%s-%s', $blocos[1], $blocos[2]));
        }

        // Só dígitos e pontuação de número: as duas chaves numéricas do
        // sistema. Qualquer outra quantidade de dígitos não é nenhuma das duas
        // e segue como nome, que é o que devolve "nenhum cadastro corresponde"
        // em vez de uma consulta a esmo.
        if ($digitos !== '' && $digitos === $compacto) {
            if (strlen($digitos) === 11) {
                return new self(self::CPF, $limpo, $digitos);
            }

            if (strlen($digitos) === self::DIGITOS_DO_MICROCHIP) {
                return new self(self::MICROCHIP, $limpo, $digitos);
            }
        }

        return new self(self::NOME, $limpo, $limpo);
    }

    public function vazio(): bool
    {
        return $this->valor === '';
    }

    /**
     * RF13, RF18 — as três chaves exatas identificam um cadastro determinado, e
     * é por isso que revelar a existência dele é resposta admissível: quem
     * digitou o CPF já o conhecia. O nome não identifica ninguém, e por ele a
     * segunda seção da tela responde apenas que *algo* corresponde — nunca o
     * quê, e nunca quantos (RN12).
     */
    public function chaveExata(): bool
    {
        return $this->tipo !== self::NOME;
    }

    /**
     * Termo curto não revela cadastro fora do âmbito do prestador. Duas letras
     * corresponderiam a meia base, e a segunda seção da tela viraria contador
     * de quantos cadastros existem — enumeração por outro nome. Dentro do
     * âmbito de autorização a busca continua valendo com o que se digitar: ali
     * o prestador já pode ver tudo o que encontrar.
     */
    public function podeRevelarExistencia(): bool
    {
        return $this->chaveExata() || mb_strlen($this->valor) >= self::MINIMO_PARA_REVELAR_EXISTENCIA;
    }

    /**
     * O dígito verificador, conferido antes de qualquer consulta: a tela
     * anuncia que "nenhuma consulta foi feita e nada foi registrado", e um
     * número digitado errado não pode gerar registro de acesso ao CPF de
     * outra pessoa.
     *
     * A conta é a de App\Rules\CpfValido, e não uma segunda
     * cópia dela: dois algoritmos de dígito verificador no mesmo sistema é um
     * a mais do que se pode manter em acordo.
     */
    public function cpfConfere(): bool
    {
        if ($this->tipo !== self::CPF) {
            return true;
        }

        $confere = true;

        (new CpfValido)
            ->validate('termo', $this->valor, function () use (&$confere): void {
                $confere = false;
            });

        return $confere;
    }

    /**
     * RF52 — "natureza do dado acessado". É o que T14 devolve ao tutor: por
     * qual chave o procuraram.
     */
    public function naturezaDoRegistro(): string
    {
        return match ($this->tipo) {
            self::CPF => RegistroDeAcesso::BUSCA_POR_CPF,
            self::CODIGO => RegistroDeAcesso::BUSCA_POR_CODIGO,
            self::MICROCHIP => RegistroDeAcesso::BUSCA_POR_MICROCHIP,
            default => RegistroDeAcesso::BUSCA_POR_NOME,
        };
    }
}
