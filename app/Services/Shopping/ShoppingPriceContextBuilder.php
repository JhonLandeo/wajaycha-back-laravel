<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Prices\PriceContext;
use App\DTOs\Shopping\PlanningWeek;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\PriceSourceRegistry;
use Throwable;

/**
 * Reads the quotes for the products on a list and hands them over as a value.
 *
 * Reading prices can never fail the request: an exception is reported and the
 * plan is computed without a price context (the phase-1 shape plus null price
 * keys). No source is fetched from here; ingestion runs on the queue.
 */
final class ShoppingPriceContextBuilder
{
    public function __construct(
        private readonly PriceRepositoryContract $priceRepository,
        private readonly PriceSourceRegistry $priceRegistry,
    ) {}

    /**
     * @param  array<int, int>  $productIds
     */
    public function build(array $productIds, PlanningWeek $week): ?PriceContext
    {
        try {
            return new PriceContext(
                quotes: $this->priceRepository->quotesFor(
                    array_values(array_unique($productIds)),
                    $this->priceRegistry->cutoffsFor($week->asOf),
                ),
                policies: $this->priceRegistry->enabledPolicies(),
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
