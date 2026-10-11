<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige `transactions.date_operation` de las filas escritas antes de fijar la
 * zona horaria de la sesion de PostgreSQL.
 *
 * Hasta 630fc34 (2026-08-06) la conexion `pgsql` no declaraba `timezone`, asi que
 * la sesion corria en UTC. Los importadores escriben la fecha como texto naive en
 * hora de Lima ('Y-m-d H:i:s', ver `TransactionYapeImport`), y una columna
 * `timestamptz` interpreta ese texto en la zona de la sesion: "16/03 00:00" de
 * Lima quedo guardado como 16/03 00:00 UTC, que en Lima se lee 15/03 19:00. Toda
 * fila de esa epoca esta cinco horas antes de lo que paso.
 *
 * Medido en la replica: las 3590 filas de extracto creadas antes del pin se leen
 * a las 19:00 del dia anterior, y las 356 creadas despues a las 00:00. No hay
 * ninguna transaccion creada entre 2026-08-05 21:39 y 2026-08-15 11:57, asi que el
 * corte no deja filas dudosas a ningun lado.
 *
 * El corte se lee de `created_at`, que es `timestamp` sin zona escrito por PHP en
 * hora de Lima. El literal se compara contra esa misma hora de pared y la
 * condicion queda sargable.
 *
 * Solo se mueven las tres fuentes que tenian filas antes del pin (en la replica:
 * import_app 3368, import_statement 4089, manual 3). Las capturas quedan fuera:
 * no existia ninguna, y una lista cerrada no puede alcanzar por accidente una
 * fuente que nadie midio.
 *
 * El efecto visible no era solo cosmetico. `alreadyImported()` compara con sesenta
 * segundos de tolerancia, y una copia vieja cinco horas antes no la reconoce:
 * reimportar un periodo de Yape volvia a escribir cada movimiento.
 *
 * Se corre una sola vez por naturaleza: la tabla `migrations` es la que impide
 * sumar las cinco horas dos veces. `updated_at` no se toca a proposito: esto no es
 * una edicion de nadie.
 */
return new class extends Migration
{
    private const CUTOVER = '2026-08-06 00:00:00';

    private const SOURCES = ['import_app', 'import_statement', 'manual'];

    public function up(): void
    {
        $this->shift('+');
    }

    public function down(): void
    {
        $this->shift('-');
    }

    private function shift(string $sign): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::SOURCES), '?'));

        DB::update(
            "UPDATE transactions
             SET date_operation = date_operation {$sign} interval '5 hours'
             WHERE created_at < ?
               AND source_type IN ({$placeholders})",
            [self::CUTOVER, ...self::SOURCES],
        );
    }
};
