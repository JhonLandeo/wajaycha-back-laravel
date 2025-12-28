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
                    CREATE MATERIALIZED VIEW public.mv_unified_transactions
                    TABLESPACE pg_default
                    AS WITH transactions_base AS (
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
                    SELECT transactions_base.id,
                        transactions_base.message,
                        transactions_base.amount,
                        transactions_base.date_operation,
                        transactions_base.type_transaction,
                        transactions_base.category_id,
                        transactions_base.detail_id,
                        transactions_base.detail_name,
                        transactions_base.matched_yape_id,
                        transactions_base.user_id,
                        transactions_base.source_type
                    FROM transactions_base
                    UNION ALL
                    SELECT yapes_unmatched.id,
                        yapes_unmatched.message,
                        yapes_unmatched.amount,
                        yapes_unmatched.date_operation,
                        yapes_unmatched.type_transaction,
                        yapes_unmatched.category_id,
                        yapes_unmatched.detail_id,
                        yapes_unmatched.detail_name,
                        yapes_unmatched.matched_yape_id,
                        yapes_unmatched.user_id,
                        yapes_unmatched.source_type
                    FROM yapes_unmatched
                    WITH DATA;
        SQL;
        DB::unprepared($sql);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP MATERIALIZED VIEW IF EXISTS public.mv_unified_transactions;');
    }
};
