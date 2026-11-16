<?php

declare(strict_types=1);

namespace App\Services\Shopping;

use App\DTOs\Prices\PriceContext;
use App\DTOs\Prices\ResolvedPrice;
use App\DTOs\Prices\ResolvedQuote;
use App\Enums\PriceState;
use App\Enums\Unit;
use App\Services\Prices\CostCalculator;
use App\Services\Prices\PriceResolver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Costo de compra de los faltantes de una receta, usando las cotizaciones que
 * ya existen para la semana (ADR-0042).
 *
 * La despensa cubre una parte; solo se valoriza lo que falta. Entre las fuentes
 * disponibles para ese producto/unidad se prefiere una cotización fresca y, de
 * las frescas, la más barata. Si no hay precio, el faltante no vale cero: la
 * estimación queda incompleta y la sugerencia lo penaliza.
 */
final class MenuPriceEstimator
{
    public function __construct(
        private readonly PriceResolver $resolver,
        private readonly CostCalculator $cost,
    ) {}

    /**
     * @param  array<int, array{name: string, unit: Unit, quantity: float}>  $requirements
     * @param  array<int, array{quantity: float, unit: Unit, expiresSoon: bool}>  $available
     * @return array{cost_cents: int|null, complete: bool, unpriced: int}
     */
    public function estimate(
        array $requirements,
        array $available,
        ?PriceContext $prices,
        CarbonImmutable $asOf,
    ): array {
        if ($prices === null) {
            return ['cost_cents' => null, 'complete' => false, 'unpriced' => count($requirements)];
        }

        $costCents = 0;
        $complete = true;
        $unpriced = 0;

        foreach ($requirements as $productId => $requirement) {
            $stock = $available[$productId] ?? null;
            $availableQuantity = $stock !== null && $stock['unit'] === $requirement['unit']
                ? $stock['quantity']
                : 0.0;
            $missing = round(max(0.0, $requirement['quantity'] - $availableQuantity), 3);

            if ($missing <= 0.0) {
                continue;
            }

            $resolved = $this->resolver->resolve(
                $productId,
                $requirement['unit'],
                $prices->quotes,
                $prices->policies,
                $asOf,
            );
            $quote = $this->cheapest($resolved);

            if ($quote === null) {
                $complete = false;
                $unpriced++;

                continue;
            }

            try {
                $costCents += $this->cost->lineCostCents($missing, $quote->quote->unitPrice);
            } catch (InvalidArgumentException) {
                $complete = false;
                $unpriced++;
            }
        }

        return ['cost_cents' => $costCents, 'complete' => $complete, 'unpriced' => $unpriced];
    }

    private function cheapest(ResolvedPrice $resolved): ?ResolvedQuote
    {
        $quotes = $resolved->chosen !== null
            ? [$resolved->chosen, ...$resolved->alternatives]
            : $resolved->alternatives;

        $fresh = array_values(array_filter(
            $quotes,
            static fn (ResolvedQuote $quote): bool => $quote->state === PriceState::Fresh,
        ));
        $pool = $fresh !== [] ? $fresh : $quotes;

        usort($pool, static fn (ResolvedQuote $a, ResolvedQuote $b): int => (float) $a->quote->unitPrice <=> (float) $b->quote->unitPrice
            ?: $a->quote->source <=> $b->quote->source);

        return $pool[0] ?? null;
    }
}
