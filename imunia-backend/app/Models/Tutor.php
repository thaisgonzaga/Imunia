<?php

namespace App\Models;

use App\Support\DocumentosLegais;
use Database\Factories\TutorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'nome', 'cpf', 'termos_aceitos_em', 'termos_versao'])]
class Tutor extends Model
{
    /** @use HasFactory<TutorFactory> */
    use HasFactory;

    protected $table = 'tutores';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'termos_aceitos_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, Tutor>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Nulo em quem foi cadastrado pela clínica e ainda não ativou a conta: o
     * cadastro é feito por terceiro, e ninguém aceita termos em nome de outro.
     * O aceite vem depois, na ativação do convite.
     */
    public function aceitouOsTermos(): bool
    {
        return $this->termos_aceitos_em !== null;
    }

    /**
     * A versão gravada é sempre a vigente no servidor, nunca a informada pelo
     * cliente: qual documento estava no ar é fato do sistema.
     */
    public function registrarAceiteDosTermos(): void
    {
        $this->forceFill([
            'termos_aceitos_em' => now(),
            'termos_versao' => DocumentosLegais::VERSAO,
        ])->save();
    }

    /**
     * Titularidade dos animais (RN10). A relação é a única forma de chegar aos
     * animais no ambiente do tutor: partir do tutor autenticado é o que impede,
     * por construção, que o painel enxergue animal alheio.
     *
     * @return HasMany<Animal, Tutor>
     */
    public function animais(): HasMany
    {
        return $this->hasMany(Animal::class);
    }
}
