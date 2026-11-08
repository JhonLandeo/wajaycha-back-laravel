<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una version de monto de una fuente de ingreso.
 *
 * `effective_from` y `effective_to` son fechas de mes completo: el primer dia
 * del mes inicial y el ultimo del final. La UI manda meses y la accion los
 * normaliza; el modelo no hace aritmetica de calendario.
 *
 * @property int $id
 * @property int $income_source_id
 * @property string $amount
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 * @property-read IncomeSource $source
 */
class IncomeSourceVersion extends Model
{
    /** @use HasFactory<\Database\Factories\IncomeSourceVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'income_source_id',
        'amount',
        'effective_from',
        'effective_to',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<IncomeSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class, 'income_source_id');
    }
}
