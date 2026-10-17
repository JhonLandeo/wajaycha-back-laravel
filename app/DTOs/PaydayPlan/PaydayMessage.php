<?php

declare(strict_types=1);

namespace App\DTOs\PaydayPlan;

/**
 * A Telegram message the payday plan wants to send: text plus the
 * `inline_keyboard` rows (empty when there are no buttons).
 */
final readonly class PaydayMessage
{
    /**
     * @param  array<int, array<int, array<string, string>>>  $keyboard
     */
    public function __construct(
        public string $text,
        public array $keyboard = [],
    ) {}
}
