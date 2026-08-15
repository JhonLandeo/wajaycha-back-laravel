<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

/**
 * What `BlindnessDetector` produces: the whole set of unwatchable categories for one
 * user in one month, collapsed into a single fact (design.md §5.1: "One observation
 * for the whole set, `subject_key = 'blindness'`, `band = 'blind'`"). This is why the
 * detector never returns an array of these — a category-by-category blindness report
 * would need one `subject_key` per category, and the owner's decision (task
 * 4.5b/4.5c) is exactly the opposite: one observation, per user, per month.
 */
final class BlindnessObservation
{
    /**
     * @param  string[]  $categoryNames  every unwatchable category's name, in the
     *                                   order the detector found them
     */
    public function __construct(
        public readonly array $categoryNames,
        public readonly float $totalSpent,
    ) {}
}
