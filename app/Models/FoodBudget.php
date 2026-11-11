<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * El presupuesto mensual de comida de un usuario y las categorías que delimitan
 * su gasto real.
 *
 * El monto es explícito y no se deriva de `categories.monthly_budget`: una
 * categoría padre con hijas no debe sumar dos veces, y renombrar o dividir
 * categorías no puede cambiar el presupuesto. Las categorías solo responden
 * "qué gasto entra".
 *
 * @property int $id
 * @property int $user_id
 * @property string $monthly_amount
 * @property int $shopping_day
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Category> $categories
 * @property-read User $user
 */
class FoodBudget extends Model
{
    /** @use HasFactory<\Database\Factories\FoodBudgetFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'monthly_amount',
        'shopping_day',
    ];

    protected $casts = [
        'monthly_amount' => 'decimal:2',
        'shopping_day' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'food_budget_categories',
            'food_budget_id',
            'category_id'
        )->withPivot('user_id')->withTimestamps();
    }
}
