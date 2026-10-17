<?php

declare(strict_types=1);

namespace App\DTOs\PaydayPlan;

use App\Enums\PaydayAnswer;
use App\Enums\PaydaySkipReason;

/**
 * A parsed `p:` callback: an answer or a skip reason for a given cycle, or the
 * cycle-less "Cobré hoy".
 */
final readonly class PaydayCallback
{
    /** The callback_data of the Cobré hoy button. It carries no cycle id: only the latest cycle is answerable. */
    public const PAID = 'p:paid';

    private function __construct(
        public ?int $cycleId,
        public ?PaydayAnswer $answer,
        public ?PaydaySkipReason $reason,
        private bool $paid,
    ) {}

    public static function answer(int $cycleId, PaydayAnswer $answer): self
    {
        return new self($cycleId, $answer, null, false);
    }

    public static function reason(int $cycleId, PaydaySkipReason $reason): self
    {
        return new self($cycleId, null, $reason, false);
    }

    public static function paid(): self
    {
        return new self(null, null, null, true);
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }
}
