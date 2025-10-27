<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    protected $guarded = [];

    public function paretoClassification(): BelongsTo
    {
        return $this->belongsTo(ParetoClassification::class);
    }

    public function categorizationRules()
    {
        return $this->hasMany(CategorizationRule::class);
    }
}
