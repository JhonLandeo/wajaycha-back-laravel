<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialEntity extends Model
{
    protected $fillable = [
        'name',
        'country',
        'website'
    ];

    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }
}
