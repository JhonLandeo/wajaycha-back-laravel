<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'pareto_classification_id',
        'type',
        'parent_id',
        'name',
        'user_id',
        'monthly_budget'
    ];

    public function paretoClassification(): BelongsTo
    {
        return $this->belongsTo(ParetoClassification::class);
    }

    public function categorizationRules(): HasMany
    {
        return $this->hasMany(CategorizationRule::class);
    }
}
