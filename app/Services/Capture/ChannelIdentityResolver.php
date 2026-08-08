<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Models\ChannelIdentity;
use App\Models\User;

/**
 * Resolves the sender of a capture to a User through their channel identity.
 *
 * The identifier comes from the channel itself, never from the message body: a
 * sender-supplied identifier would let anyone write into another user's ledger.
 */
class ChannelIdentityResolver
{
    public function resolve(string $channel, string $externalId): ?User
    {
        return ChannelIdentity::query()
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->first()
            ?->user;
    }
}
