<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a unit price was obtained. `Equivalence` means a curated conversion was
 * needed (for example 1 palta = 0.2 kg) — the consumer prefixes it with "~".
 */
enum PriceBasis: string
{
    case Measured = 'measured';
    case Equivalence = 'equivalence';
}
