<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * O recorte da auditoria de acessos (T14, RF53): qual animal, que período e —
 * quando o tutor chegou de T12 — qual prestador.
 *
 * Os três nascem juntos aqui, e não soltos no serviço, porque a tela precisa
 * devolvê-los ao tutor tal como estão em vigor: o desenho de T14 desenha cada
 * filtro como uma etiqueta que se remove, e uma etiqueta só pode ser removida
 * se a tela souber que ela está lá. O serviço consulta; este objeto é o que a
 * tela e a consulta têm a dizer um ao outro.
 *
 * O período é o único que tem valor padrão, e ele é o do desenho: doze meses.
 * Um livro de acessos aberto em "todo o período" cresceria sem fim numa tela
 * que se lê rolando, e o tutor que procura o acesso de anteontem não precisa
 * carregar o de dois anos atrás para encontrá-lo.
 */
final class FiltroDeAcessos
{
    public const ULTIMOS_30_DIAS = '30d';

    public const ULTIMOS_12_MESES = '12m';

    public const TODO_O_PERIODO = 'tudo';

    public const PERIODO_PADRAO = self::ULTIMOS_12_MESES;

    private function __construct(
        /** Código do animal (`IM-XXXX-XXXX`); nulo quando o tutor vê todos. */
        public readonly ?string $animal,
        public readonly string $periodo,
        /** Prestador que T12 pediu para destacar; nulo na entrada direta. */
        public readonly ?int $prestador,
    ) {}

    /**
     * @param  array<string, mixed>  $validados
     */
    public static function de(array $validados): self
    {
        $animal = self::texto($validados['animal'] ?? null);
        $periodo = self::texto($validados['periodo'] ?? null);
        $prestador = $validados['prestador'] ?? null;

        return new self(
            animal: $animal === null ? null : strtoupper($animal),
            periodo: in_array($periodo, self::periodos(), true) ? $periodo : self::PERIODO_PADRAO,
            prestador: $prestador === null ? null : (int) $prestador,
        );
    }

    /**
     * @return list<string>
     */
    public static function periodos(): array
    {
        return [self::ULTIMOS_30_DIAS, self::ULTIMOS_12_MESES, self::TODO_O_PERIODO];
    }

    /**
     * O começo da janela, ou nulo quando não há começo — que é o que "todo o
     * período" quer dizer.
     */
    public function desde(): ?Carbon
    {
        return match ($this->periodo) {
            self::ULTIMOS_30_DIAS => Carbon::now()->subDays(30),
            self::ULTIMOS_12_MESES => Carbon::now()->subMonths(12),
            default => null,
        };
    }

    public function temRecorte(): bool
    {
        return $this->animal !== null
            || $this->prestador !== null
            || $this->periodo !== self::TODO_O_PERIODO;
    }

    /**
     * As opções de período, com o rótulo que a tela exibe. Vêm do servidor
     * junto do que está em vigor para que o seletor de T14 não precise repetir,
     * em português, uma lista que já é do domínio.
     *
     * @return list<array{chave: string, rotulo: string}>
     */
    public static function opcoesDePeriodo(): array
    {
        return [
            ['chave' => self::ULTIMOS_30_DIAS, 'rotulo' => 'Últimos 30 dias'],
            ['chave' => self::ULTIMOS_12_MESES, 'rotulo' => 'Últimos 12 meses'],
            ['chave' => self::TODO_O_PERIODO, 'rotulo' => 'Todo o período'],
        ];
    }

    public function rotuloDoPeriodo(): string
    {
        foreach (self::opcoesDePeriodo() as $opcao) {
            if ($opcao['chave'] === $this->periodo) {
                return $opcao['rotulo'];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function paraResposta(): array
    {
        return [
            'animal' => $this->animal,
            'periodo' => $this->periodo,
            'rotulo_periodo' => $this->rotuloDoPeriodo(),
            'prestador' => $this->prestador,
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        $limpo = trim((string) $valor);

        return $limpo === '' ? null : $limpo;
    }
}
