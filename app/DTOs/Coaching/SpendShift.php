<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

/**
 * How one category's spending moved between two comparable windows.
 *
 * `delta` is in soles and not a percentage, and that is the decision this class
 * exists to hold. A percentage on a small base is noise wearing the costume of a
 * finding: S/ 2 becoming S/ 20 is "+900%", reads like an emergency, and is
 * eighteen soles. The absolute figure is the one a person can weigh against their
 * own month, and it is the only one that stays meaningful when the previous window
 * was zero — where a percentage is not merely misleading but undefined.
 */
final class SpendShift
{
    /**
     * @param  int|null  $categoryId  null for spending the categoriser has not
     *                                filed yet. It still counts: dropping it would
     *                                make the total disagree with the ledger, and
     *                                a hole in categorisation is itself something
     *                                the reader can act on.
     * @param  float  $delta  `current - previous`. **Signed on purpose** — the
     *                        direction is half the finding, and a message that
     *                        sorts by magnitude still has to say which way.
     */
    public function __construct(
        public readonly ?int $categoryId,
        public readonly string $name,
        public readonly float $current,
        public readonly float $previous,
        public readonly float $delta,
    ) {}

    /** Spending that did not exist in the previous window. */
    public function isNew(): bool
    {
        return $this->previous <= 0.0 && $this->current > 0.0;
    }

    /** Spending that stopped. */
    public function isGone(): bool
    {
        return $this->current <= 0.0 && $this->previous > 0.0;
    }

    public function rose(): bool
    {
        return $this->delta > 0.0;
    }
}
