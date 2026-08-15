<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FinancialEntity extends Model
{
    /** @use HasFactory<\Database\Factories\FinancialEntityFactory> */
    use HasFactory;

    /**
     * The row `FinancialEntitiesSeeder` inserts first — Banco de Crédito del Perú.
     *
     * Carries the same caveat as {@see \App\Models\PaymentService::YAPE_ID}: it is
     * an assumption about seeded ids, not a fact the schema enforces.
     */
    public const BCP_ID = 1;

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
