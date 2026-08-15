<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Que clase de duplicado une un par, que no es un matiz sino dos operaciones con
 * reglas distintas.
 *
 * La distincion existe porque la base necesita protegerlas de forma diferente. Un
 * asiento del banco explica UN pago — los montos se comparan exactos — asi que un
 * maestro cruzado con dos satelites es un defecto, y
 * `unq_reconciliation_candidates_cross_source_master` lo impide. La misma fuente
 * trayendo tres copias del mismo movimiento es otra cosa: ahi el grupo entero
 * colapsa sobre un sobreviviente y dos satelites sobre un maestro es lo correcto.
 *
 * Sin este valor en la fila, la base no puede distinguir las dos y termina sin
 * proteger ninguna.
 */
enum ReconciliationKind: string
{
    /** Dos puertas distintas trajeron el mismo movimiento. Uno a uno. */
    case CROSS_SOURCE = 'cross_source';

    /** La misma puerta lo trajo mas de una vez. El grupo colapsa sobre uno. */
    case SAME_SOURCE = 'same_source';

    public static function between(?string $aSource, ?string $bSource): self
    {
        return $aSource === $bSource ? self::SAME_SOURCE : self::CROSS_SOURCE;
    }
}
