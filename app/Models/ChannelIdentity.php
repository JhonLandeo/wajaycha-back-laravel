<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binds one account on one capture channel to the User who owns it.
 *
 * `external_id` is per-channel and its shape matters: for `whatsapp` it holds E.164
 * digits without the leading plus; for `telegram` it will hold the raw chat id.
 * Whoever adds a channel must decide that shape and keep the write path and the
 * read path agreeing on it, because a mismatch produces an identity nobody will
 * ever match.
 *
 * @property int $id
 * @property int $user_id
 * @property string $channel
 * @property string $external_id
 * @property \Illuminate\Support\Carbon $linked_at
 * @property string|null $legacy_whatsapp_phone
 */
class ChannelIdentity extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelIdentityFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'channel',
        'external_id',
        'linked_at',
        'legacy_whatsapp_phone',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
