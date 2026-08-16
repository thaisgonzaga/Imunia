<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'nome', 'cpf', 'termos_aceitos_em'])]
class Tutor extends Model
{
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
}
