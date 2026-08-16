<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Emissão de histórico em PDF (RF46). Esta fatia usa o registro apenas do lado
 * da leitura, na verificação pública (RF47); a geração do documento é a fatia
 * de T15.
 */
#[Fillable(['codigo', 'resumo', 'animal_nome', 'animal_especie', 'emitido_por', 'emitido_em'])]
class Exportacao extends Model
{
    use HasFactory;

    /**
     * Caracteres do identificador da emissão, lidos em quatro grupos de quatro.
     */
    public const COMPRIMENTO_CODIGO = 16;

    /**
     * Caracteres do resumo mostrados ao conferente. O resumo guardado é o
     * sha-256 inteiro; o rodapé do PDF e a tela exibem este mesmo prefixo, para
     * que a comparação a olho nu seja possível (RF47b).
     */
    public const COMPRIMENTO_RESUMO_ABREVIADO = 16;

    protected $table = 'exportacoes';

    protected function casts(): array
    {
        return [
            'emitido_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, Exportacao>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitido_por');
    }

    /**
     * Aceita o código como o conferente o tem diante de si — com os espaços dos
     * grupos, em qualquer caixa —, porque errar a forma de digitar não é errar
     * o código.
     */
    public static function normalizarCodigo(?string $valor): string
    {
        return Str::of($valor ?? '')
            ->upper()
            ->replaceMatches('/[^0-9A-F]/', '')
            ->limit(self::COMPRIMENTO_CODIGO, '')
            ->toString();
    }

    public static function localizar(string $codigo): ?self
    {
        return static::query()->where('codigo', static::normalizarCodigo($codigo))->first();
    }

    public function resumoAbreviado(): string
    {
        return Str::upper(substr($this->resumo, 0, self::COMPRIMENTO_RESUMO_ABREVIADO));
    }

    /**
     * Confere o resumo que veio no QR Code contra o que foi emitido. Divergência
     * significa que o arquivo em mãos não é mais o documento registrado sob
     * aquele código.
     */
    public function confere(string $resumo): bool
    {
        return hash_equals($this->resumoAbreviado(), Str::upper($resumo));
    }
}
