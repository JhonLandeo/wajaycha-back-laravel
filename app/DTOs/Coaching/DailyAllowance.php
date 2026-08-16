<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

/**
 * What one monthly budget leaves for the rest of the month, spread over the days
 * that are actually left.
 *
 * The counterpart to {@see PaceObservation}, and the contrast is the point.
 * A `PaceObservation` exists only when something crossed a line, which is what
 * makes it publishable unprompted. A `DailyAllowance` exists for every budgeted
 * category whether or not anything happened, because the question it answers was
 * asked out loud — and an answer that omits the categories where nothing went
 * wrong is not a smaller answer, it is a wrong one.
 */
final class DailyAllowance
{
    /**
     * @param  float  $remaining  budget minus month-to-date spend. **Signed on
     *                            purpose**: negative is the category that is
     *                            already over, and collapsing it to zero here
     *                            would throw away the one figure that says by how
     *                            much.
     * @param  float  $perDay  the remaining amount divided by the days left,
     *                         today included. Exactly `0.0` once `$remaining`
     *                         stops being positive — a negative daily allowance is
     *                         arithmetic with no reading, and "podés gastar
     *                         S/ -4.00 por día" is not a sentence.
     */
    public function __construct(
        public readonly int $categoryId,
        public readonly string $name,
        public readonly float $budget,
        public readonly float $spent,
        public readonly float $remaining,
        public readonly float $perDay,
    ) {}

    public function hasRoom(): bool
    {
        return $this->remaining > 0.0;
    }
}
