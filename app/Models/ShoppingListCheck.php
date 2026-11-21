<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A product the user already put in the cart for a given week (ADR-0048 D5).
 *
 * The row exists only while the product is checked; unchecking deletes it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $week_start
 * @property int $product_id
 */
class ShoppingListCheck extends Model
{
    /** @use HasFactory<\Database\Factories\ShoppingListCheckFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'week_start',
        'product_id',
    ];

    protected $casts = [
        'week_start' => 'date',
    ];
}
