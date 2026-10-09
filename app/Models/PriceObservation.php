<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price seen at a source for a catalogue product. Global: there is no user.
 *
 * Money columns are `decimal:4` casts, which Eloquent returns as strings — a
 * unit price must reach the cost arithmetic without ever being a float.
 *
 * @property-read Product|null $product
 */
class PriceObservation extends Model
{
    /** @use HasFactory<\Database\Factories\PriceObservationFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'source',
        'price_kind',
        'unit',
        'unit_price',
        'reference_price',
        'price_min',
        'price_max',
        'sample_size',
        'basis',
        'period_start',
        'period_end',
        'observed_at',
        'source_ref',
        'is_quarantined',
    ];

    protected $casts = [
        'price_kind' => PriceKind::class,
        'basis' => PriceBasis::class,
        'unit_price' => 'decimal:4',
        'reference_price' => 'decimal:4',
        'price_min' => 'decimal:4',
        'price_max' => 'decimal:4',
        'sample_size' => 'integer',
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
        'observed_at' => 'immutable_datetime',
        'is_quarantined' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
