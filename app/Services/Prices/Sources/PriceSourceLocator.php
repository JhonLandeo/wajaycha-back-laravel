<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use App\Exceptions\Prices\UnknownPriceSource;
use Illuminate\Contracts\Container\Container;

/**
 * Source key to adapter. The map is the only list of adapters in the code: a
 * new store (a future `metro`) is one more entry here plus its config block.
 */
final class PriceSourceLocator
{
    /** @var array<string, class-string<PriceSource>> */
    private const ADAPTERS = [
        'inei' => IneiSource::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @throws UnknownPriceSource
     */
    public function for(string $key): PriceSource
    {
        $class = self::ADAPTERS[$key] ?? throw UnknownPriceSource::named($key);

        /** @var PriceSource */
        return $this->container->make($class);
    }
}
