<?php

declare(strict_types=1);

namespace App\Services\Prices\Normalization;

/**
 * What a quoted quantity measures. Mass is in kilograms, volume in litres,
 * count in units. Grams and millilitres are converted to these (by the pack-size parser) before a Measure exists.
 */
enum Dimension: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';
}
