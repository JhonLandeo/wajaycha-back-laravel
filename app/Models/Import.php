<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $extension
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property int $user_id
 * @property int|null $payment_service_id
 * @property int|null $financial_entity_id
 * @property string $status
 * @property-read string|null $financial_entity_name
 * @property-read string|null $payment_service_name
 * @property-read string $url
 * @property-read FinancialEntity|null $financialEntity
 * @property-read PaymentService|null $paymentService
 */
class Import extends Model
{
    use HasFactory;
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

    public function getFinancialEntityNameAttribute(): ?string
    {
        /** @var FinancialEntity|null $entity */
        $entity = $this->financialEntity;
        return $entity?->name;
    }

    public function getPaymentServiceNameAttribute(): ?string
    {
        /** @var PaymentService|null $service */
        $service = $this->paymentService;
        return $service?->name;
    }

    public function getUrlAttribute(): string
    {
        return Storage::url('files/' . $this->name);
    }
}
