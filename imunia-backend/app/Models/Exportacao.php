<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Emissão de histórico em PDF (RF46). A verificação pública (RF47) lê daqui a
 * autenticidade, a data e o retrato do animal; a emissão (T15) escreve a linha
 * e guarda o arquivo sob o mesmo código.
 */
#[Fillable(['codigo', 'resumo', 'animal_id', 'animal_nome', 'animal_especie', 'emitido_por', 'emitido_em'])]
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
     * @return BelongsTo<Animal, Exportacao>
     */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /**
     * O código como o rodapé o imprime e a tela o exibe: quatro grupos de
     * quatro, a mesma forma que `normalizarCodigo()` desfaz na volta.
     */
    public function codigoFormatado(): string
    {
        return trim(chunk_split($this->codigo, 4, ' '));
    }

    /**
     * Onde o arquivo emitido fica guardado. O caminho deriva do código porque o
     * documento é a emissão: não há segunda versão de um PDF cujo resumo está
     * gravado na linha.
     */
    public function caminhoDoArquivo(): string
    {
        return 'exportacoes/'.$this->codigo.'.pdf';
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
