<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ingrediente de una receta, con su cantidad y unidad declaradas.
 *
 * La unidad puede diferir de la canónica del producto (200 g de arroz sobre un
 * producto en kg); la conversión es responsabilidad de la capa que agrega
 * necesidades, no de esta fila.
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $product_id
 * @property string $quantity
 * @property string $unit
 * @property bool $is_optional
 * @property-read Recipe $recipe
 * @property-read Product $product
 */
class RecipeIngredient extends Model
{
    /** @use HasFactory<\Database\Factories\RecipeIngredientFactory> */
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'product_id',
        'quantity',
        'unit',
        'is_optional',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'is_optional' => 'boolean',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
