<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceKind;
use App\Enums\PriceState;

/**
 * How one ENABLED source is read: its kind, rank, staleness windows and the
 * attribution template. Built from `config/prices.php` by
 * `PriceSourceRegistry`; the pure deciders receive it as a value, so a source
 * that is absent from the policies map is disabled as far as they can tell.
 */
final class PricePolicy
{
    public function __construct(
        public readonly string $source,
        public readonly PriceKind $kind,
        public readonly string $label,
        public readonly int $rank,
        public readonly int $freshDays,
        public readonly int $staleDays,
        public readonly ?string $attribution,
    ) {}

    /**
     * The state for a quote this many days old, or null once it is expired
     * (older than the stale window) — expired quotes are dropped, not shown.
     */
    public function classify(int $ageDays): ?PriceState
    {
        return match (true) {
            $ageDays <= $this->freshDays => PriceState::Fresh,
            $ageDays <= $this->staleDays => PriceState::Stale,
            default => null,
        };
    }
}
