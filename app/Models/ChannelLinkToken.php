<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, expiring proof of account ownership.
 *
 * The plaintext token exists only in the response that issues it and in the deep
 * link the user taps. It is never an attribute of this model, so it cannot leak
 * through a dump, a log line or a serialised job payload.
 *
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $redeemed_at
 * @property int $user_id
 */
class ChannelLinkToken extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelLinkTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'redeemed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
