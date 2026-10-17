<?php

declare(strict_types=1);

namespace App\Services\PaydayPlan;

use App\DTOs\PaydayPlan\PaydayCallback;
use App\Enums\PaydayAnswer;
use App\Enums\PaydaySkipReason;

/**
 * Turns inbound `callback_data` into a {@see PaydayCallback}, answering null for
 * anything that is not exactly one of the shapes this feature emits.
 *
 * Buttons live in chat history forever, so malformed or unknown input is a
 * normal case that gets a polite reply, never an exception.
 */
final class PaydayCallbackParser
{
    public function parse(?string $data): ?PaydayCallback
    {
        if ($data === null) {
            return null;
        }

        if ($data === PaydayCallback::PAID) {
            return PaydayCallback::paid();
        }

        // `\z`, not `$`: `$` also matches before a trailing newline.
        if (preg_match('/\Ap:([1-9][0-9]{0,17}):(y|n|w)\z/', $data, $m) === 1) {
            return PaydayCallback::answer((int) $m[1], PaydayAnswer::from($m[2]));
        }

        if (preg_match('/\Ap:([1-9][0-9]{0,17}):r:([a-z])\z/', $data, $m) === 1) {
            $reason = PaydaySkipReason::fromCode($m[2]);

            return $reason === null ? null : PaydayCallback::reason((int) $m[1], $reason);
        }

        return null;
    }
}
