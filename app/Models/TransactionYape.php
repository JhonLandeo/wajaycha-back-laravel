<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionYape extends Model
{
    protected $guarded = [];

    protected $fillable = [
        'date_operation',
        'message',
        'origin',
        'destination',
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
}
