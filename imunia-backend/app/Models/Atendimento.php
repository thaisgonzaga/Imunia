<?php

namespace App\Models;

use Database\Factories\AtendimentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Prontuário de um atendimento clínico (RF31). É registro clínico: imutável
 * depois de confirmado (RN26, decisoes.md §3.4) — esta fatia só lê, e a escrita
 * é de V07; a retificação, de V09.
 *
 * A imutabilidade não é imposta por este modelo, e sim pela ausência de qualquer
 * caminho de escrita sobre registro existente: não há `update` a chamar, porque
 * corrigir é criar outro registro apontando para este (RF33).
 */
#[Fillable([
    'animal_id',
    'prestador_id',
    'retifica_atendimento_id',
    'motivo_retificacao',
    'atendido_em',
    'titulo',
    'motivo',
    'anamnese',
    'exame_fisico',
    'hipoteses_diagnosticas',
    'diagnostico',
    'conduta',
    'peso_kg',
    'profissional_user_id',
    'profissional_nome',
    'profissional_crmv',
    'retorno_em',
    'retorno_finalidade',
])]
class Atendimento extends Model
{
    /** @use HasFactory<AtendimentoFactory> */
    use HasFactory;

    protected $table = 'atendimentos';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'atendido_em' => 'datetime',
            'retorno_em' => 'date',

            // Medição, e com a precisão de uma balança de consultório: sem o
            // molde, o MySQL devolveria "12.40" como cadeia de caracteres e a
            // comparação com o peso anterior — que a tela de V08 exibe — seria
            // entre textos.
            'peso_kg' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Animal, Atendimento>
     */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /**
     * @return BelongsTo<Prestador, Atendimento>
     */
    public function prestador(): BelongsTo
    {
        return $this->belongsTo(Prestador::class);
    }

    /**
     * O veterinário autor. É a ele, e só a ele, que RN27 reserva a retificação.
     *
     * @return BelongsTo<User, Atendimento>
     */
    public function profissional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profissional_user_id');
    }

    /**
     * @return HasMany<AnexoAtendimento, Atendimento>
     */
    public function anexos(): HasMany
    {
        return $this->hasMany(AnexoAtendimento::class);
    }

    /**
     * O registro que este corrige — nulo, portanto, em todo atendimento que não
     * seja uma retificação (RF33).
     *
     * @return BelongsTo<Atendimento, Atendimento>
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(Atendimento::class, 'retifica_atendimento_id');
    }

    /**
     * A correção deste registro, quando existir. O par com `original()` é o que
     * torna o encadeamento navegável nos dois sentidos (RF33b): do original
     * chega-se à retificação, e da retificação, de volta ao original.
     *
     * @return HasOne<Atendimento, Atendimento>
     */
    public function retificacao(): HasOne
    {
        return $this->hasOne(Atendimento::class, 'retifica_atendimento_id');
    }

    public function ehRetificacao(): bool
    {
        return $this->retifica_atendimento_id !== null;
    }
}
