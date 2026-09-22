<?php

namespace App\Models;

use Database\Factories\VersaoProtocoloFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma versão do cálculo de calendário (RF24, X02). Reúne os parâmetros
 * temporais de todos os imunobiológicos sob um mesmo rótulo — "2024.1" — e é
 * ela que se publica e se encerra, nunca o parâmetro isolado.
 *
 * As três situações não são estados de um ciclo qualquer: rascunho é o único
 * momento em que os valores mudam; vigente é a única versão que alimenta
 * cálculo novo; encerrada permanece para sempre, porque RN32 promete que uma
 * data emitida em 2025 continue explicável pelos parâmetros de 2025.
 */
#[Fillable(['rotulo', 'situacao', 'base', 'publicado_em', 'encerrado_em'])]
class VersaoProtocolo extends Model
{
    /** @use HasFactory<VersaoProtocoloFactory> */
    use HasFactory;

    protected $table = 'versoes_protocolo';

    public const RASCUNHO = 'rascunho';

    public const VIGENTE = 'vigente';

    public const ENCERRADA = 'encerrada';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'publicado_em' => 'datetime',
            'encerrado_em' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ProtocoloVacinal, VersaoProtocolo>
     */
    public function protocolos(): HasMany
    {
        return $this->hasMany(ProtocoloVacinal::class);
    }

    public function rascunho(): bool
    {
        return $this->situacao === self::RASCUNHO;
    }

    public function vigente(): bool
    {
        return $this->situacao === self::VIGENTE;
    }

    /**
     * Quantos cálculos foram realizados sob esta versão — o número que a lista
     * de X02 exibe em cada cartão. Conta registros de vacinação, e não linhas
     * de parâmetro: é a vacinação que guarda qual versão a explicou (RF24c).
     */
    public function calculosRealizados(): int
    {
        return Vacinacao::query()
            ->whereIn('protocolo_vacinal_id', $this->protocolos()->select('id'))
            ->count();
    }
}
