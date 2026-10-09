<?php

declare(strict_types=1);

namespace App\Services\Prices\Normalization;

use App\Enums\PriceBasis;

/**
 * Either a price per catalogue unit (four-decimal string) with how it was
 * obtained, or the reason it could not be obtained.
 */
final class NormalizedPrice
{
    private function __construct(
        public readonly ?string $unitPrice,
        public readonly PriceBasis $basis,
        public readonly ?string $rejection,
    ) {}

    public static function of(string $unitPrice, PriceBasis $basis): self
    {
        return new self($unitPrice, $basis, null);
    }

    public static function rejected(string $reason): self
    {
        return new self(null, PriceBasis::Measured, $reason);
    }

    public function isRejected(): bool
    {
        return $this->rejection !== null;
    }
}
