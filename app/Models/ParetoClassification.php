<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ParetoClassification extends Model
{
    /** @use HasFactory<\Database\Factories\ParetoClassificationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'percentage',
        'user_id'
    ];
}
