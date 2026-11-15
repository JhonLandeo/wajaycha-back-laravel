<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración del menú semanal: personas, comidas y días que el usuario
 * planifica.
 *
 * @property int $id
 * @property int $user_id
 * @property int $household_size
 * @property array<int, string> $meal_types
 * @property array<int, int> $plan_weekdays
 * @property array<int, string> $excluded_terms
 * @property int|null $max_prep_minutes
 * @property \App\Enums\MenuStrategy $menu_strategy
 * @property array<int, string>|null $available_equipment
 * @property-read User $user
 */
class MealPlanSettings extends Model
{
    /** @use HasFactory<\Database\Factories\MealPlanSettingsFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'household_size',
        'meal_types',
        'plan_weekdays',
        'excluded_terms',
        'max_prep_minutes',
        'menu_strategy',
        'available_equipment',
    ];

    protected $casts = [
        'household_size' => 'integer',
        'meal_types' => 'array',
        'plan_weekdays' => 'array',
        'excluded_terms' => 'array',
        'max_prep_minutes' => 'integer',
        'menu_strategy' => \App\Enums\MenuStrategy::class,
        'available_equipment' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
