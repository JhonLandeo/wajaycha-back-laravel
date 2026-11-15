<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecipeCourse;
use App\Enums\RecipeCuisine;
use App\Enums\RecipeOrigin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una receta del catálogo global (user_id NULL) o privada del usuario.
 *
 * `base_servings` es el rendimiento tal como está escrita; el menú escala
 * cantidades desde ahí. `meal_types` define en qué comidas se puede proponer.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $slug
 * @property string $name
 * @property int $base_servings
 * @property array<int, string> $meal_types
 * @property RecipeCourse $course
 * @property RecipeOrigin $origin
 * @property RecipeCuisine $cuisine
 * @property array<int, string> $required_equipment
 * @property array<int, string> $diet_tags
 * @property int|null $prep_minutes
 * @property string|null $instructions
 * @property bool $is_active
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecipeIngredient> $ingredients
 * @property-read User|null $user
 */
class Recipe extends Model
{
    /** @use HasFactory<\Database\Factories\RecipeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'slug',
        'name',
        'base_servings',
        'meal_types',
        'course',
        'origin',
        'cuisine',
        'required_equipment',
        'diet_tags',
        'prep_minutes',
        'instructions',
        'is_active',
    ];

    protected $casts = [
        'base_servings' => 'integer',
        'meal_types' => 'array',
        'course' => RecipeCourse::class,
        'origin' => RecipeOrigin::class,
        'cuisine' => RecipeCuisine::class,
        'required_equipment' => 'array',
        'diet_tags' => 'array',
        'prep_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }
}
