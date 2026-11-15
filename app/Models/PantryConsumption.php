<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un lote de despensa consumido por una comida cocinada (ADR-0020).
 *
 * Es append-only: se agrega al marcar `cooked` y se borra al desmarcar. Nunca
 * modifica `PantryItem`; el disponible se calcula como adquisiciones menos
 * consumos.
 *
 * @property int $id
 * @property int $user_id
 * @property int $pantry_item_id
 * @property int $meal_plan_entry_id
 * @property int $product_id
 * @property string $quantity
 * @property string $unit
 * @property \Illuminate\Support\Carbon $consumed_on
 * @property string $reason
 * @property-read PantryItem $pantryItem
 * @property-read MealPlanEntry $mealPlanEntry
 * @property-read Product $product
 */
class PantryConsumption extends Model
{
    /** @use HasFactory<\Database\Factories\PantryConsumptionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pantry_item_id',
        'meal_plan_entry_id',
        'product_id',
        'quantity',
        'unit',
        'consumed_on',
        'reason',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'consumed_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pantryItem(): BelongsTo
    {
        return $this->belongsTo(PantryItem::class);
    }

    public function mealPlanEntry(): BelongsTo
    {
        return $this->belongsTo(MealPlanEntry::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
