<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a price observation measures. Only `Retail` can price a shopping-list
 * line; `Wholesale` feeds the trend annotation and nothing else (spec
 * "Resolution precedence for a line price").
 */
enum PriceKind: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
}
