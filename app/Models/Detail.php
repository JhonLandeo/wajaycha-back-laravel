<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Transaction;
class Detail extends Model
{
    protected $table = 'details';

    protected $fillable = [
        'description',
        'user_id',
        'embedding',
        'last_used_category_id',
        'distance'
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
