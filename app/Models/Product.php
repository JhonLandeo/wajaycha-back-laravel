<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un producto del catálogo de abarrotes.
 *
 * Los valores nutricionales son aproximados y están expresados por unidad
 * canónica (`kg`, `l`, `unidad`, ...), no por 100 g (ADR-0031). Una receta que
 * declara gramos convierte a la unidad canónica antes de multiplicar.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $slug
 * @property string $name
 * @property string $unit
 * @property float|null $calories_kcal
 * @property float|null $protein_g
 * @property float|null $carbs_g
 * @property float|null $fat_g
 * @property int|null $shelf_life_days
 * @property bool $is_active
 */
class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'slug',
        'name',
        'unit',
        'calories_kcal',
        'protein_g',
        'carbs_g',
        'fat_g',
        'shelf_life_days',
        'is_active',
    ];

    protected $casts = [
        'calories_kcal' => 'float',
        'protein_g' => 'float',
        'carbs_g' => 'float',
        'fat_g' => 'float',
        'shelf_life_days' => 'integer',
        'is_active' => 'boolean',
    ];
}
