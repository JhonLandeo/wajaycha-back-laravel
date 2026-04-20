<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Eliminar la vista (usando tu método down)
        $this->down();

        // 2. Cambiar el tipo de columna en la tabla física
        Schema::table('transactions', function (Blueprint $table) {
            // Es vital añadir ->change()
            $table->timestampTz('date_operation')->change();
        });

        // 3. Recrear la vista
        $sql = <<<SQL
            CREATE VIEW public.v_unified_transactions AS 
            WITH transactions_base AS (
                SELECT t.id,
                    t.message,
                    t.amount,
                    t.date_operation, -- Ahora esta columna será timestamptz
                    t.type_transaction,
                    t.category_id,
                    t.detail_id,
                    d.description AS detail_name,
                    t.yape_id AS matched_yape_id,
                    t.user_id,
                    'transaction'::text AS source_type
                FROM transactions t
                    JOIN details d ON d.id = t.detail_id
            ), yapes_unmatched AS (
                SELECT ty.id,
                    ty.message,
                    ty.amount,
                    ty.date_operation,
                    ty.type_transaction,
                    ty.category_id,
                    ty.detail_id,
                    d.description AS detail_name,
                    ty.id AS matched_yape_id,
                    ty.user_id,
                    'yape_unmatched'::text AS source_type
                FROM transaction_yapes ty
                    JOIN details d ON d.id = ty.detail_id
                WHERE NOT (EXISTS ( SELECT 1
                        FROM transactions t
                        WHERE t.yape_id = ty.id))
            )
            SELECT * FROM transactions_base
            UNION ALL
            SELECT * FROM yapes_unmatched;
        SQL;

        DB::unprepared($sql);
    }

    public function down(): void
    {
        // 1. Eliminar la vista
        DB::unprepared('DROP VIEW IF EXISTS public.v_unified_transactions;');

        // 2. Revertir el tipo de columna si es necesario
        // Ojo: Asegúrate de que la tabla 'transactions' exista antes de intentar el change
        if (Schema::hasColumn('transactions', 'date_operation')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->timestamp('date_operation')->change();
            });
        }
    }
};
