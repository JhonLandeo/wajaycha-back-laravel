<?php

declare(strict_types=1);

namespace App\DTOs\Coaching;

/**
 * What FinancialCoachingService::speak() should evaluate: every category (the
 * scheduled sweep) or just the one a transaction just landed in (the capture-time
 * check's counterfactual, design.md §5.3).
 */
final class CoachingScope
{
    private function __construct(
        public readonly bool $isSweep,
        public readonly ?int $categoryId = null,
        public readonly ?float $amount = null,
    ) {}

    public static function sweep(): self
    {
        return new self(isSweep: true);
    }

    public static function forCategory(int $categoryId, float $amount): self
    {
        return new self(isSweep: false, categoryId: $categoryId, amount: $amount);
    }
}
