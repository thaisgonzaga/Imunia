<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'tipo',
    'nome',
    'documento',
    'telefone',
    'endereco',
    'municipio',
    'uf',
    'responsavel_tecnico_nome',
    'responsavel_tecnico_crmv',
    'responsavel_tecnico_crmv_uf',
])]
class Prestador extends Model
{
    use HasFactory;

    protected $table = 'prestadores';

    /**
     * @return BelongsToMany<User, Prestador>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'prestador_usuario')
            ->withPivot('papel', 'crmv', 'crmv_uf')
            ->withTimestamps();
    }
}
