<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One accepted delivery from a capture channel.
 *
 * A row here means "already handled". Its only job is to make the unique index
 * refuse the second attempt.
 *
 * @property \Illuminate\Support\Carbon $processed_at
 */
class ProcessedChannelUpdate extends Model
{
    /** @use HasFactory<\Database\Factories\ProcessedChannelUpdateFactory> */
    use HasFactory;

    protected $fillable = [
        'channel',
        'external_update_id',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
