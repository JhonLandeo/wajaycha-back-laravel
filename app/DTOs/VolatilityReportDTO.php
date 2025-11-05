<?php

namespace App\DTOs;

use App\Enums\VolatilityDiagnostic;

class VolatilityReportDTO
{
    public function __construct(
        public readonly int|string $categoria,
        public readonly float $volatilidad,
        public readonly VolatilityDiagnostic $diagnostico
    ) {
    }
}