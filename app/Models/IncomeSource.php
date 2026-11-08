<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncomeSourceKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una fuente de ingreso recurrente del usuario (sueldo, negocio, otro).
 *
 * El monto no vive aca: vive en {@see IncomeSourceVersion}, porque puede cambiar
 * durante el año y el planificado de un mes tiene que leer la version vigente en
 * ese mes, no la ultima que se escribio.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property IncomeSourceKind $kind
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IncomeSourceVersion> $versions
 * @property-read User $user
 */
class IncomeSource extends Model
{
    /** @use HasFactory<\Database\Factories\IncomeSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'kind',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => IncomeSourceKind::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<IncomeSourceVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(IncomeSourceVersion::class)->orderBy('effective_from');
    }
}
