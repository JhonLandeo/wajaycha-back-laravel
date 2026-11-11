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
 * @property int|null $max_prep_minutes
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
        'max_prep_minutes',
    ];

    protected $casts = [
        'household_size' => 'integer',
        'meal_types' => 'array',
        'plan_weekdays' => 'array',
        'max_prep_minutes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
