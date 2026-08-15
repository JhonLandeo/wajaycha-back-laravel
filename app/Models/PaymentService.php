<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentService extends Model
{
    /**
     * The row `PaymentServicesSeeder` inserts first.
     *
     * Fragile in a specific way, and named here so the fragility is visible instead
     * of being spelled `1` at each call site: it holds only while the sequence
     * agrees with it. Resolving the wallet by name instead of by id would be
     * sturdier, but it can return null, and that is a different change — one with
     * its own behaviour to specify and test.
     */
    public const YAPE_ID = 1;

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
