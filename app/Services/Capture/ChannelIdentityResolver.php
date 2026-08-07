<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Models\ChannelIdentity;
use App\Models\User;
use Illuminate\Support\LazyCollection;

/**
 * Resolves the sender of a capture to a User through their channel identity,
 * and — since design.md D4 — the reverse direction Coaching needs: given a user,
 * which channel identity to speak on.
 *
 * The identifier comes from the channel itself, never from the message body: a
 * sender-supplied identifier would let anyone write into another user's ledger.
 *
 * The reverse lookup stays here because Capture owns `channel_identities`;
 * Coaching consumes it and never queries the table directly (design.md D4).
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

    /**
     * Picks the channel identity to speak on for a user, honouring the caller's
     * ordered preference — never insertion order, never query-plan luck
     * (design.md D4). `channel_identities` is unique on (channel, external_id),
     * not on user_id, so a user may legitimately hold more than one identity;
     * this is what makes that ambiguity deterministic.
     *
     * @param  string[]  $channels  ordered preference list, e.g. ['telegram', 'whatsapp']
     */
    public function preferredIdentityFor(int $userId, array $channels): ?ChannelIdentity
    {
        $identitiesByChannel = ChannelIdentity::query()
            ->select(['id', 'user_id', 'channel', 'external_id', 'linked_at'])
            ->where('user_id', $userId)
            ->whereIn('channel', $channels)
            ->get()
            ->keyBy('channel');

        foreach ($channels as $channel) {
            if ($identitiesByChannel->has($channel)) {
                return $identitiesByChannel->get($channel);
            }
        }

        return null;
    }

    /**
     * The distinct user ids reachable on any of the given channels, lazily —
     * the sweep iterates every user in the system and must never load the whole
     * `channel_identities` table into memory at once.
     *
     * A user reachable only through a channel absent from `$channels` (e.g. a
     * WhatsApp-only user when `$channels === ['telegram']`) is simply absent
     * from this collection — never an error, never a WhatsApp fallback
     * (ADR-0007, design.md D4).
     *
     * @param  string[]  $channels
     * @return LazyCollection<int, int>
     */
    public function userIdsReachableOn(array $channels): LazyCollection
    {
        return ChannelIdentity::query()
            ->select(['user_id'])
            ->whereIn('channel', $channels)
            ->distinct()
            ->cursor()
            ->pluck('user_id');
    }
}
