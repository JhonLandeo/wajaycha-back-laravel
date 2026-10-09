<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

/**
 * Everything the pure plan service needs to put prices on a list: the stored
 * quotes for the user's products and the policies of the ENABLED sources. A
 * plan computed without a context is the phase-1 plan with null price keys.
 */
final class PriceContext
{
    /**
     * @param  Quote[]  $quotes
     * @param  array<string, PricePolicy>  $policies  enabled sources, keyed by source key
     */
    public function __construct(
        public readonly array $quotes,
        public readonly array $policies,
    ) {}
}
