<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Enums\MealType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una comida planificada para una fecha.
 *
 * `servings` es lo que se come; `cook_servings` es lo que se cocina. Una receta
 * con cook_servings > servings produce sobras, y una entrada `leftover`
 * referencia la entrada que las produjo. Así los ingredientes se cuentan una
 * sola vez.
 *
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $plan_date
 * @property MealType $meal_type
 * @property MealEntryKind $kind
 * @property MealEntryStatus $status
 * @property int|null $recipe_id
 * @property int $servings
 * @property int $cook_servings
 * @property int|null $leftover_of_entry_id
 * @property string|null $notes
 * @property-read Recipe|null $recipe
 * @property-read MealPlanEntry|null $leftoverOf
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MealPlanEntry> $leftovers
 * @property-read User $user
 */
class MealPlanEntry extends Model
{
    /** @use HasFactory<\Database\Factories\MealPlanEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_date',
        'meal_type',
        'kind',
        'status',
        'recipe_id',
        'servings',
        'cook_servings',
        'leftover_of_entry_id',
        'notes',
    ];

    protected $casts = [
        'plan_date' => 'date',
        'meal_type' => MealType::class,
        'kind' => MealEntryKind::class,
        'status' => MealEntryStatus::class,
        'servings' => 'integer',
        'cook_servings' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function leftoverOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'leftover_of_entry_id');
    }

    public function leftovers(): HasMany
    {
        return $this->hasMany(self::class, 'leftover_of_entry_id');
    }
}
