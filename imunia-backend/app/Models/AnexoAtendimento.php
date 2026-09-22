<?php

namespace App\Models;

use Database\Factories\AnexoAtendimentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Exame ou documento anexado a um atendimento (RF32). Herda a imutabilidade do
 * registro a que se vincula (RN28) e nunca expõe o próprio caminho: quem lê o
 * arquivo passa pela rota que confere a autorização (RF32c).
 */
#[Fillable([
    'atendimento_id',
    'descricao',
    'exame_em',
    'tipo',
    'mime',
    'tamanho_bytes',
    'caminho',
])]
class AnexoAtendimento extends Model
{
    /** @use HasFactory<AnexoAtendimentoFactory> */
    use HasFactory;

    protected $table = 'anexos_atendimento';

    /**
     * O disco é privado de propósito: o anexo não tem URL pública, e o único
     * caminho até o arquivo é a rota autorizada (RF32c).
     */
    public const DISCO = 'local';

    /**
     * RN28 — a restrição de formato e de tamanho, num lugar só: é o que V08
     * valida no envio, o que a tela anuncia antes de o profissional escolher o
     * arquivo e o que a mensagem de recusa cita ao explicar o motivo. Três
     * textos que precisam dizer o mesmo número.
     *
     * PDF e imagem, e nada mais, porque RF32 é essa lista — e porque o anexo é
     * documento fechado: um arquivo de editor de texto muda de aparência com o
     * programa que o abre, e o laudo que instrui a conduta de outro
     * profissional não pode depender disso.
     */
    public const TAMANHO_MAXIMO_KB = 10240;

    /** @var list<string> */
    public const MIMES_ACEITOS = ['application/pdf', 'image/jpeg', 'image/png'];

    /** @var list<string> */
    public const EXTENSOES_ACEITAS = ['pdf', 'jpg', 'jpeg', 'png'];

    /**
     * O `tipo` da coluna a partir do formato conferido no envio. Documento é o
     * PDF; imagem, o resto do que a lista admite.
     */
    public static function tipoDe(string $mime): string
    {
        return $mime === 'application/pdf' ? 'documento' : 'imagem';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exame_em' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Atendimento, AnexoAtendimento>
     */
    public function atendimento(): BelongsTo
    {
        return $this->belongsTo(Atendimento::class);
    }

    /**
     * Se o arquivo está mesmo no disco. O registro do anexo é imutável (RN28),
     * mas o arquivo pode não responder — e T08 prevê exatamente esse estado: o
     * bloco do anexo avisa e oferece nova tentativa, enquanto o restante do
     * prontuário continua legível. Um prontuário que deixa de abrir inteiro
     * porque um exame não carregou é pior do que a falha que o causou.
     */
    public function disponivel(): bool
    {
        return Storage::disk(self::DISCO)->exists($this->caminho);
    }
}
