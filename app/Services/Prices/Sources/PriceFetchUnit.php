<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

/**
 * One unit of work of a source. `$key` is what the run log stores as
 * `unit_key`: a catalogue slug for Plaza Vea, `all` for the sources that fetch
 * a whole table in one go.
 */
final class PriceFetchUnit
{
    public function __construct(public readonly string $key) {}
}
