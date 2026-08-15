<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property float|null $distance
 */
class Detail extends Model
{
    /** @use \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\DetailFactory> */
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    protected $fillable = [
        'description',
        'user_id',
        'embedding',
        'last_used_category_id',
        'distance',
        'operation_type',
        'entity_clean',
        'ai_reviewed_at',
        'ai_verdict',
    ];

    /**
     * `embedding` es `vector(768)`. Sin el cast, Eloquent recibe un array de
     * PHP y PDO no lo puede bindear, así que la escritura nunca llegaba a la
     * columna en un formato que PostgreSQL entienda.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => \App\Casts\Vector::class,
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'detail_id');
    }
}
