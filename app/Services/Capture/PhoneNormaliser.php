<?php

declare(strict_types=1);

namespace App\Services\Capture;

/**
 * Reduces a stored phone number to the shape channel_identities.external_id holds
 * for WhatsApp: E.164 digits without the leading plus.
 *
 * Registration and the one-time backfill must agree on this shape exactly. If they
 * drift, a user gets an identity nobody will ever match and the bot tells them they
 * are not linked.
 */
class PhoneNormaliser
{
    /** E.164 allows at most 15 digits; below eight there is no plausible number. */
    private const MIN_DIGITS = 8;

    private const MAX_DIGITS = 15;

    /** Returns the normalised digits, or null when the input cannot be trusted. */
    public function normalise(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $length = strlen($digits);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        return $digits;
    }
}
