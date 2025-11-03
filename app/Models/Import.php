<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Import extends Model
{
    protected $guarded = [];

    public function financialEntity(): BelongsTo
    {
        return $this->belongsTo(FinancialEntity::class);
    }

    public function paymentService(): BelongsTo
    {
        return $this->belongsTo(PaymentService::class);
    }
}
