<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParetoClassification extends Model
{
    protected $guarded = [];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
