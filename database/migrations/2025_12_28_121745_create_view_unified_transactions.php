<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->down();
        $sql = <<<SQL
                    CREATE VIEW public.v_unified_transactions AS 
                    WITH transactions_base AS (
                        SELECT t.id,
                            t.message,
                            t.amount,
                            t.date_operation,
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP MATERIALIZED VIEW IF EXISTS public.v_unified_transactions;');
    }
};
