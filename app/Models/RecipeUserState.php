<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado por usuario de una receta global (ADR-0030).
 *
 * @property int $id
 * @property int $user_id
 * @property int $recipe_id
 * @property bool $is_enabled
 * @property-read Recipe $recipe
 */
class RecipeUserState extends Model
{
    protected $fillable = [
        'user_id',
        'recipe_id',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
