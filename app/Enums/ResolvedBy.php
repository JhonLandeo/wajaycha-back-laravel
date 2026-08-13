<?php

declare(strict_types=1);

namespace App\Enums;

enum ResolvedBy: string
{
    /** The pair was tight enough that the system reconciled it without asking. */
    case SYSTEM = 'system';

    /** A person answered it. */
    case USER = 'user';

    public function label(): string
    {
        return match ($this) {
            self::SYSTEM => 'Unificado automáticamente',
            self::USER => 'Decidido por ti',
        };
    }
}
