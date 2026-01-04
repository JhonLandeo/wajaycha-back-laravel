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
       $sql = <<<SQL
        WITH ranked_transactions AS (
            SELECT 
                id,
                user_id, 
                amount, 
                type_transaction, 
                category_id,
                date_operation::date as fecha_op,
                ROW_NUMBER() OVER (
                    PARTITION BY user_id, amount, type_transaction, category_id, date_operation::date 
                    ORDER BY created_at ASC
                ) as rn
            FROM transactions
            WHERE yape_id IS NULL
        ),
        ranked_yapes AS (
            SELECT 
                id as yape_true_id,
                user_id, 
                amount, 
                type_transaction, 
                category_id,
                date_operation::date as fecha_op,
                ROW_NUMBER() OVER (
                    PARTITION BY user_id, amount, type_transaction, category_id, date_operation::date 
                    ORDER BY created_at ASC
                ) as rn
            FROM transaction_yapes
        )
        UPDATE transactions t
        SET yape_id = ry.yape_true_id
        FROM ranked_transactions rt
        JOIN ranked_yapes ry ON 
            rt.user_id = ry.user_id AND
            rt.amount = ry.amount AND
            rt.type_transaction = ry.type_transaction AND
            rt.fecha_op = ry.fecha_op AND
            rt.category_id IS NOT DISTINCT FROM ry.category_id AND
            rt.rn = ry.rn
        WHERE t.id = rt.id;
       SQL;
       DB::unprepared($sql);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        
    }
};
