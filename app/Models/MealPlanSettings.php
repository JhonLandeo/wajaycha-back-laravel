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
 * @property \App\Enums\DietProfile $diet_profile
 * @property int $new_recipes_per_week
 * @property int|null $daily_calorie_target
 * @property int|null $dinner_carb_limit_g
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
        'diet_profile',
        'new_recipes_per_week',
        'daily_calorie_target',
        'dinner_carb_limit_g',
    ];

    protected $casts = [
        'household_size' => 'integer',
        'meal_types' => 'array',
        'plan_weekdays' => 'array',
        'excluded_terms' => 'array',
        'max_prep_minutes' => 'integer',
        'menu_strategy' => \App\Enums\MenuStrategy::class,
        'available_equipment' => 'array',
        'diet_profile' => \App\Enums\DietProfile::class,
        'new_recipes_per_week' => 'integer',
        'daily_calorie_target' => 'integer',
        'dinner_carb_limit_g' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
