<?php

namespace App\Support;

/**
 * O estado dos filtros do diretório de prestadores (T10, RF11).
 *
 * Existe por causa de uma distinção que a tela precisa manter e que uma string
 * vazia sozinha não expressa: "não escolhi município nenhum ainda" é diferente
 * de "escolhi ver todos os municípios". O primeiro caso admite a sugestão que o
 * desenho pede — o filtro pré-preenchido pelo município conhecido do tutor —, e
 * o segundo é justamente o resultado de o tutor ter limpado essa sugestão. Sem
 * a distinção, a ação de limpar não teria efeito: a sugestão voltaria na
 * requisição seguinte.
 *
 * Quem carrega a distinção pela requisição é a *presença* do parâmetro, e não
 * o valor dele. `?municipio=` (vazio, presente) é a escolha explícita por
 * todos; parâmetro ausente é a tela recém-aberta.
 */
final class FiltroDoDiretorio
{
    private function __construct(
        /** Busca por nome do estabelecimento; nulo quando o campo está vazio. */
        public readonly ?string $nome,
        public readonly ?string $municipio,
        public readonly ?string $uf,
        /** O município veio da requisição — inclusive vazio — e não da sugestão. */
        private readonly bool $municipioExplicito,
        /** O município em vigor foi sugerido pelo sistema, e a tela pode limpá-lo. */
        public readonly bool $sugerido = false,
    ) {
    }

    /**
     * @param array<string, mixed> $validados
     */
    public static function de(array $validados, bool $municipioInformado): self
    {
        $nome = self::texto($validados['nome'] ?? null);
        $municipio = self::texto($validados['municipio'] ?? null);
        $uf = self::texto($validados['uf'] ?? null);

        return new self(
            nome: $nome,
            municipio: $municipio,
            // A UF só filtra acompanhando um município. Sozinha, ela viraria
            // uma busca por estado inteiro que o desenho não tem e que a lista
            // de municípios do seletor já cobre melhor.
            uf: $municipio === null ? null : ($uf === null ? null : strtoupper($uf)),
            municipioExplicito: $municipioInformado,
        );
    }

    public function municipioEhExplicito(): bool
    {
        return $this->municipioExplicito;
    }

    /**
     * O filtro como ele vai à consulta. Sem município escolhido, entra o
     * sugerido — marcado como tal, para que a tela o exiba com a ação de
     * limpar em vez de fingir que o tutor o escolheu.
     *
     * @param array{municipio: string, uf: string}|null $sugestao
     */
    public function comSugestao(?array $sugestao): self
    {
        if ($this->municipio !== null || $this->municipioExplicito || $sugestao === null) {
            return $this;
        }

        return new self(
            nome: $this->nome,
            municipio: $sugestao['municipio'],
            uf: $sugestao['uf'],
            municipioExplicito: false,
            sugerido: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function paraResposta(): array
    {
        return [
            'nome' => $this->nome,
            'municipio' => $this->municipio,
            'uf' => $this->uf,
            'rotulo_municipio' => $this->municipio === null
                ? null
                : ($this->uf === null ? $this->municipio : "{$this->municipio}, {$this->uf}"),
            'sugerido' => $this->sugerido,
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        $limpo = trim(preg_replace('/\s+/', ' ', (string) $valor));

        return $limpo === '' ? null : $limpo;
    }
}
