<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategorizationRule extends Model
{
    protected $table = 'categorization_rules';

    protected $fillable = [
        'detail_id',
        'category_id',
        'user_id',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
