<?php

declare(strict_types=1);

namespace App\DTOs\Prices;

use App\Enums\PriceState;

/**
 * The resolver's verdict for one product: the chosen quote (if any), its state,
 * and the other usable retail quotes as alternatives. `Unknown` carries no
 * quote and no alternatives — it is never a zero price.
 */
final class ResolvedPrice
{
    /**
     * @param  ResolvedQuote[]  $alternatives
     */
    public function __construct(
        public readonly PriceState $state,
        public readonly ?ResolvedQuote $chosen,
        public readonly array $alternatives,
    ) {}

    public static function unknown(): self
    {
        return new self(PriceState::Unknown, null, []);
    }
}
