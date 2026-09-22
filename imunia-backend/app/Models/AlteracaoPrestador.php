<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma alteração do cadastro do prestador (RF08): que campo mudou, de quê para
 * quê, por quem e quando.
 *
 * Imutável, como todo registro de auditoria do sistema (ver `RegistroDeAcesso`):
 * não há caminho de escrita que atualize uma linha destas, e a tabela sequer
 * tem `updated_at` para tanto.
 */
#[Fillable([
    'prestador_id',
    'alterado_por_user_id',
    'campo',
    'de',
    'para',
    'ocorrido_em',
])]
class AlteracaoPrestador extends Model
{
    protected $table = 'alteracoes_prestador';

    /** Nada aqui é atualizado, e por isso não há o que carimbar. */
    public $timestamps = false;

    /**
     * Só os campos que identificam o estabelecimento entram no histórico, e
     * não o cadastro inteiro. RF08 fala em "alterações relevantes para a
     * identificação em documentos já emitidos": trocar o telefone de contato
     * não desmente um PDF antigo; trocar a razão social, sim, e trocar o
     * município muda onde a clínica é encontrada no diretório (RF08b).
     */
    private const CAMPOS_HISTORIADOS = [
        'nome' => 'Razão social ou nome',
        'cnpj' => 'CNPJ',
        'municipio' => 'Município',
        'uf' => 'UF',
        'responsavel_tecnico_nome' => 'Responsável técnico',
        'responsavel_tecnico_crmv' => 'CRMV do responsável técnico',
    ];

    /**
     * @return BelongsTo<User, AlteracaoPrestador>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alterado_por_user_id');
    }

    /**
     * Grava uma linha por campo historiado que mudou de fato. Compara valores
     * já normalizados, para que salvar o formulário sem tocar em nada não
     * produza histórico algum — um histórico que registra não-alterações deixa
     * de ser legível justamente quando é mais necessário.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     */
    public static function registrar(Prestador $prestador, User $autor, array $antes, array $depois): void
    {
        $agora = Carbon::now();

        foreach (self::CAMPOS_HISTORIADOS as $campo => $rotulo) {
            $de = $antes[$campo] ?? null;
            $para = $depois[$campo] ?? null;

            if ($de === $para) {
                continue;
            }

            static::create([
                'prestador_id' => $prestador->id,
                'alterado_por_user_id' => $autor->id,
                'campo' => $rotulo,
                'de' => $de,
                'para' => $para,
                'ocorrido_em' => $agora,
            ]);
        }
    }
}
