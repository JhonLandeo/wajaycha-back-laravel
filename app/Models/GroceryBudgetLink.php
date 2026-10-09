<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroceryBudgetLink extends Model
{
    /** @use HasFactory<\Database\Factories\GroceryBudgetLinkFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'resolved_by',
        'linked_at',
    ];

    protected $casts = [
        'linked_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
