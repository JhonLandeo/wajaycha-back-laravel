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
        'category_id',
        'amount',
        'date_operation',
        'type_transaction',
        'user_id',
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
        return $this->hasOneThrough(Category::class, Category::class);
    }

    public function splits()
    {
        return $this->hasMany(TransactionSplit::class);
    }

    // public function getAmountAttribute()
    // {
    //     return $this->splits->sum('amount');
    // }
}
