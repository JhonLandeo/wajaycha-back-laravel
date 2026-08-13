<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Transaction $transaction
 * @property-read Transaction $candidateTransaction
 */
class ReconciliationCandidate extends Model
{
    /** @use HasFactory<\Database\Factories\ReconciliationCandidateFactory> */
    use HasFactory;

    protected $table = 'reconciliation_candidates';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'candidate_transaction_id',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'resolved_by' => ResolvedBy::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function candidateTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'candidate_transaction_id');
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ReconciliationStatus::PENDING);
    }
}
