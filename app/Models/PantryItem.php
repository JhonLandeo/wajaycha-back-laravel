<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PantryItem extends Model
{
    /** @use HasFactory<\Database\Factories\PantryItemFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'unit',
        'acquisition_source',
        'acquired_on',
        'expires_on',
        'note',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'acquired_on' => 'date',
        'expires_on' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
