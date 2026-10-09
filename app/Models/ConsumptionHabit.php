<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Product|null $product
 */
class ConsumptionHabit extends Model
{
    /** @use HasFactory<\Database\Factories\ConsumptionHabitFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'weekly_quantity',
        'unit',
        'is_active',
    ];

    protected $casts = [
        'weekly_quantity' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
