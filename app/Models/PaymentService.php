<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentService extends Model
{
    protected $fillable = [
        'name',
        'financial_entity_id',
        'type',
        'website'
    ];

    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }
}
