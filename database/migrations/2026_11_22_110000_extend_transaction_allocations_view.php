<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `v_transaction_allocations` pasa a cubrir **todos** los movimientos no
     * conciliados, no solo los que tienen categoría.
     *
     * La Fase 0 dejó afuera el movimiento sin categorizar porque "su monto se lee
     * de transactions". Al migrar los lectores apareció el problema: tres de
     * ellos —el resumen por categoría, el gasto por categoría del coach y el
     * dashboard— tienen un cubo "Sin categorizar" que no puede desaparecer sin
     * que el total deje de describir el mes. Peor: la alternativa era repetir un
     * UNION en cada lector, que es exactamente el tipo de regla que se olvida en
     * el siguiente.
     *
     * Con este cambio la vista es la atribución efectiva completa:
     *
     *   - reparto explícito, una fila por categoría y monto;
     *   - categoría única, una fila con el monto completo;
     *   - sin categoría, una fila con `category_id = NULL` y el monto completo.
     *
     * Un movimiento dividido **nunca** cae en la tercera rama: su `category_id`
     * es NULL, pero la existencia de splits lo excluye. Así `category_id IS NULL`
     * en la vista significa exactamente "sin categorizar", no "dividido".
     *
     * Se agrega `detail_name` porque dos lectores (top de comercios y gasto más
     * grande) lo necesitan y un JOIN a `details` en cada uno sería otra regla que
     * mantener.
     */
    public function up(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.v_transaction_allocations');

        DB::unprepared(<<<'SQL'
            CREATE VIEW public.v_transaction_allocations AS
            SELECT
                ut.id AS transaction_id,
                ut.user_id,
                ut.date_operation,
                ut.type_transaction,
                ut.detail_id,
                ut.detail_name,
                ut.amount AS transaction_amount,
                ts.category_id,
                ts.amount AS allocated_amount
            FROM v_unified_transactions ut
            JOIN transaction_splits ts ON ts.transaction_id = ut.id

            UNION ALL

            SELECT
                ut.id,
                ut.user_id,
                ut.date_operation,
                ut.type_transaction,
                ut.detail_id,
                ut.detail_name,
                ut.amount,
                ut.category_id,
                ut.amount
            FROM v_unified_transactions ut
            WHERE NOT EXISTS (
                SELECT 1
                  FROM transaction_splits ts
                 WHERE ts.transaction_id = ut.id
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.v_transaction_allocations');

        DB::unprepared(<<<'SQL'
            CREATE VIEW public.v_transaction_allocations AS
            SELECT
                ut.id AS transaction_id,
                ut.user_id,
                ut.date_operation,
                ut.type_transaction,
                ut.detail_id,
                ut.amount AS transaction_amount,
                ts.category_id,
                ts.amount AS allocated_amount
            FROM v_unified_transactions ut
            JOIN transaction_splits ts ON ts.transaction_id = ut.id

            UNION ALL

            SELECT
                ut.id,
                ut.user_id,
                ut.date_operation,
                ut.type_transaction,
                ut.detail_id,
                ut.amount,
                ut.category_id,
                ut.amount
            FROM v_unified_transactions ut
            WHERE ut.category_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1
                    FROM transaction_splits ts
                   WHERE ts.transaction_id = ut.id
              );
        SQL);
    }
};
