<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use App\DTOs\Prices\ObservationDraft;
use App\DTOs\Prices\Rejection;

/**
 * What fetching one unit produced: the drafts to store, what was rejected and
 * why, and non-price details for the run log. `$partial` is the adapter's call
 * (a rejected or quarantined INEI row makes the run partial; a Plaza Vea
 * product with no accepted candidate does not — an absent offer is not a
 * failure). `$skipped` means the unit was deliberately not fetched.
 *
 * `$details` ends up in the run log, so it must never carry a price.
 */
final class SourceFetchResult
{
    /**
     * @param  list<ObservationDraft>  $drafts
     * @param  list<Rejection>  $rejections
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly array $drafts = [],
        public readonly array $rejections = [],
        public readonly bool $partial = false,
        public readonly bool $skipped = false,
        public readonly array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function skipped(array $details): self
    {
        return new self(skipped: true, details: $details);
    }
}
