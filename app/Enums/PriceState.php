<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How trustworthy a resolved line price is, from its age against the source's
 * staleness window. `Expired` never leaves the resolver: an expired quote is
 * dropped, so a line is either fresh, stale, or unknown.
 */
enum PriceState: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Unknown = 'unknown';
}
