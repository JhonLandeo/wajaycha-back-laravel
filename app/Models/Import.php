<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Import extends Model
{
    protected $fillable = [
        'name',
        'extension',
        'path',
        'mime',
        'size',
        'user_id',
        'payment_service_id',
        'financial_entity_id',
        'status'
    ];

    public function financialEntity(): BelongsTo
    {
        return $this->belongsTo(FinancialEntity::class);
    }

    public function paymentService(): BelongsTo
    {
        return $this->belongsTo(PaymentService::class);
    }

    protected $appends = [
        'financial_entity_name',
        'payment_service_name',
        'url'
    ];

    public function getFinancialEntityNameAttribute()
    {
        return $this->financialEntity?->name;
    }

    public function getPaymentServiceNameAttribute()
    {
        return $this->paymentService?->name;
    }

    public function getUrlAttribute()
    {
        return Storage::url('files/' . $this->name);
    }
}
