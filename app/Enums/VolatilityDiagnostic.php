<?php

namespace App\Enums;

enum VolatilityDiagnostic: string
{
    case VeryStable = 'Muy estable';
    case Stable = 'Estable';
    case Normal = 'Normal';
    case Volatile = 'Volatil';
    case VeryVolatile = 'Muy Volátil';
}