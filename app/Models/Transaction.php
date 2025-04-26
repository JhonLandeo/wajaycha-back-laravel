<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Detail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property-read Detail $detail
 */
class Transaction extends Model
{
    protected $table = 'transactions';
    protected $guarded = [];
    protected $fillable = [
        'name',
        'category_id',
        'sub_category_id',
        'amount',
        'created_at',
        'updated_at',
        'detail_id',
    ];

    protected $casts = [
        'value' => 'float',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(Detail::class, 'detail_id');
    }


    public function category(): HasOneThrough
    {
        return $this->hasOneThrough(Category::class, SubCategory::class);
    }
}
