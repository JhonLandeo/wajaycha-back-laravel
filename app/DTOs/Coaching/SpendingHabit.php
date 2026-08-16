<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

use App\Enums\SpendingRhythm;

/**
 * One category's shape across several complete months.
 *
 * The figure that decides the verdict is `variation` — the coefficient of
 * variation, standard deviation over the mean. A ratio rather than an amount, on
 * purpose: the whole point is to compare a S/ 1200 rent against a S/ 60 streaming
 * subscription and find that both are equally steady, which no absolute spread can
 * do. A rent that moves by S/ 30 is fixed; a delivery habit that moves by S/ 30
 * on a S/ 40 average is nothing but choice.
 */
final class SpendingHabit
{
    /**
     * @param  float  $monthlyAverage  the mean across every month in the window,
     *                                 counting a month with no spend as zero. That
     *                                 is what makes an occasional expense average
     *                                 out to a small number and vary enormously,
     *                                 which is exactly the reading intended.
     * @param  float  $variation  standard deviation divided by the mean. Zero when
     *                            the amount never moves; grows without bound as it
     *                            does. Zero as well when there is nothing to divide.
     * @param  int  $monthsOfHistory  months elapsed since this category's first
     *                                spend inside the window — NOT the count of
     *                                months with spend. A category that spends in
     *                                two months out of six has six months of
     *                                history and is genuinely erratic; one whose
     *                                first spend was two months ago has two, and
     *                                nothing can be said about it yet.
     */
    public function __construct(
        public readonly ?int $categoryId,
        public readonly string $name,
        public readonly float $monthlyAverage,
        public readonly float $variation,
        public readonly int $monthsOfHistory,
        public readonly SpendingRhythm $rhythm,
    ) {}

    public function is(SpendingRhythm $rhythm): bool
    {
        return $this->rhythm === $rhythm;
    }
}
