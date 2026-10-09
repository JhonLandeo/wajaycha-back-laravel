<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One source's attempt at one unit of work. Holds outcomes and non-price
 * details only — it survives a purge, so it must contain nothing a purge
 * would need to delete.
 */
class PriceIngestionRun extends Model
{
    /** @use HasFactory<\Database\Factories\PriceIngestionRunFactory> */
    use HasFactory;

    protected $fillable = [
        'source',
        'unit_key',
        'status',
        'started_at',
        'finished_at',
        'rows_written',
        'rows_rejected',
        'error',
        'details',
    ];

    protected $casts = [
        'status' => PriceRunStatus::class,
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'rows_written' => 'integer',
        'rows_rejected' => 'integer',
        'details' => 'array',
    ];
}
