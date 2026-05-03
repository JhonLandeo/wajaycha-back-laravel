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
        // View to show only master records and reconcile with Yape details
        $sql = <<<SQL
            CREATE OR REPLACE VIEW public.v_unified_transactions AS 
            SELECT 
                t.id,
                t.message,
                t.amount,
                t.date_operation,
                t.type_transaction,
                t.category_id,
                t.detail_id,
                d.description AS detail_name,
                (SELECT t2.id FROM transactions t2 WHERE t2.matched_transaction_id = t.id LIMIT 1) AS matched_yape_id,
                t.user_id,
                t.source_type
            FROM transactions t
            JOIN details d ON d.id = t.detail_id
            WHERE t.matched_transaction_id IS NULL;
        SQL;
        DB::unprepared($sql);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared("DROP VIEW IF EXISTS public.v_unified_transactions CASCADE;");
    }
};
