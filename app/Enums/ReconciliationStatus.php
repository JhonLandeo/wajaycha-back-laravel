<?php

declare(strict_types=1);

namespace App\Enums;

enum ReconciliationStatus: string
{
    /** Proposed by the system, still counted twice, waiting for a person. */
    case PENDING = 'pending';

    /** The user said it is the same movement. One of the two rows is now a satellite. */
    case CONFIRMED = 'confirmed';

    /**
     * The user said they are different movements. The row is kept, not deleted:
     * it is what stops the next import from proposing the same pair again.
     */
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::CONFIRMED => 'Confirmado',
            self::REJECTED => 'Descartado',
        };
    }
}
