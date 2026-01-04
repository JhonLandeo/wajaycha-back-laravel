<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read \App\Models\Detail $detail
 */
class TransactionYape extends Model
{
    protected $guarded = [];

    protected $fillable = [
        'date_operation',
        'message',
        'amount',
        'type_transaction',
        'user_id',
        'detail_id',
        'category_id'
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(Detail::class, 'detail_id');
    }

    /**
     * @return HasMany<TransactionTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(TransactionTag::class, 'transaction_yape_id');
    }
}
