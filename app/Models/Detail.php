<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Transaction;
class Detail extends Model
{
    protected $table = 'details';
    protected $guarded = [];

    protected $fillable = [
        'name',
        'user_id',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'detail_id');
    }
}
