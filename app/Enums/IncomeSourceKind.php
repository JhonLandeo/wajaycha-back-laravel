<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * El tipo de una fuente de ingreso planificado.
 *
 * No agrega comportamiento: el ingreso planificado es la suma de las fuentes
 * activas, sea sueldo, negocio u otra. El tipo sirve para que el usuario
 * reconozca la fuente en la pantalla, no para calcular distinto.
 */
enum IncomeSourceKind: string
{
    case Salary = 'salary';
    case Business = 'business';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Salary => 'Sueldo',
            self::Business => 'Negocio',
            self::Other => 'Otro',
        };
    }
}
