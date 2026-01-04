<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Transaction;
/**
 * @property float|null $distance
 */

class Detail extends Model
{
    protected $fillable = [
        'description',
        'user_id',
        'embedding',
        'last_used_category_id',
        'distance',
        'operation_type',
        'entity_clean',
        'ai_reviewed_at',
        'ai_verdict'
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'detail_id');
    }

    public function transactionYapes(): HasMany
    {
        return $this->hasMany(TransactionYape::class, 'detail_id');
    }
}
