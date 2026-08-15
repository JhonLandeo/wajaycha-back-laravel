<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReconciliationKind;
use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Los `@property` de las columnas casteadas no son decoracion: sin ellos el
 * analisis estatico lee `status` como `string|null` y da por muerta cualquier
 * comparacion contra un case del enum — "always evaluate to true" sobre codigo
 * que en ejecucion funciona. El cast vive en `casts()`; esto es lo que lo hace
 * visible fuera de tiempo de ejecucion.
 *
 * @property int $user_id
 * @property int $transaction_id
 * @property int $candidate_transaction_id
 * @property int|null $master_transaction_id
 * @property ReconciliationKind $kind
 * @property ReconciliationStatus $status
 * @property ResolvedBy|null $resolved_by
 * @property \Carbon\CarbonImmutable|null $resolved_at
 * @property-read Transaction $transaction
 * @property-read Transaction $candidateTransaction
 */
class ReconciliationCandidate extends Model
{
    // Sin `HasFactory`: `ReconciliationCandidateFactory` nunca se escribio, y el
    // trait declaraba un generico apuntando a una clase inexistente. Los tests
    // construyen estas filas por el repositorio, que es el unico camino que la
    // aplicacion usa. Si algun dia hace falta una factory, se agrega la clase y
    // el trait juntos.

    protected $table = 'reconciliation_candidates';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'candidate_transaction_id',
        'master_transaction_id',
        'kind',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationStatus::class,
            'kind' => ReconciliationKind::class,
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
