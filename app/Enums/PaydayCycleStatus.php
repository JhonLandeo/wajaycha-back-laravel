<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one month's payday cycle stands.
 *
 * Persisted as plain text with no SQL CHECK — the rule lives in this enum, the
 * same arrangement `grocery_budget_links.resolved_by` already uses.
 */
enum PaydayCycleStatus: string
{
    /** Claimed (and normally sent); waiting for the user to answer. */
    case Pending = 'pending';

    /** The user said they separated the savings. */
    case Separated = 'separated';

    /** The user chose to skip this month; the reason is stored beside it. */
    case Skipped = 'skipped';

    /** The user has not been paid yet. The bot stays silent until they say so. */
    case Deferred = 'deferred';

    /** The month ended with no answer. Nothing can be answered any more. */
    case Expired = 'expired';

    /** The plan message never reached Telegram. The daily run retries it within the grace window. */
    case SendFailed = 'send_failed';

    /** Whether the user can still answer the buttons of this cycle. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Deferred;
    }
}
