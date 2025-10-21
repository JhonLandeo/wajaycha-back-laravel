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
                     CREATE OR REPLACE FUNCTION public.get_transactions_by_detail(
                    p_per_page integer, 
                    p_page integer, 
                    p_year integer, 
                    p_month integer, 
                    p_type type_transaction, 
                    p_amount numeric, 
                    p_search character varying, 
                    p_category character varying, 
                    p_user_id integer, 
                    p_recurring boolean, 
                    p_weekend boolean, 
                    p_workday boolean
                    )
                RETURNS TABLE(
                    detail_id bigint, 
                    detail_name character varying, 
                    child_transactions jsonb, 
                    frequency bigint, 
                    amount numeric, 
                    total_count bigint
                    )
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RETURN QUERY
                    WITH FilteredData AS (
                        SELECT 
                            t.detail_id,
                            d.name AS detail_name,
                            jsonb_agg(
                                jsonb_build_object(
                                    'id', t.id,
                                    'message', t.message,
                                    'amount', t.amount,
                                    'date_operation', t.date_operation,
                                    'type_transaction', t.type_transaction,
                                    'category_id', t.category_id,
                                    'detail_id', t.detail_id,
                                    'detail_name', d.name,
                                    'yape_trans', (
                                        SELECT jsonb_agg(
                                            jsonb_build_object(
                                                'date_operation', yape_trans.date_operation,
                                                'amount', yape_trans.amount,
                                                'origin', yape_trans.origin,
                                                'destination', yape_trans.destination,
                                                'type_transaction', yape_trans.type_transaction,
                                                'message', yape_trans.message
                                            )
                                        )
                                        FROM transaction_yapes AS yape_trans
                                        WHERE 
                                            yape_trans.amount = t.amount
                                            AND yape_trans.user_id = p_user_id
                                            AND yape_trans.date_operation::date = t.date_operation::date
                                    )
                                )
                            ) AS child_transactions,
                            COUNT(t.id) AS frequency,
                            SUM(CASE WHEN t.type_transaction::type_transaction = 'income' THEN t.amount ELSE 0 END) 
                            - SUM(CASE WHEN t.type_transaction::type_transaction = 'expense' THEN t.amount ELSE 0 END) AS amount
                        FROM 
                            transactions AS t
                        JOIN 
                            details AS d ON d.id = t.detail_id
                        WHERE 
                            t.user_id = p_user_id
                            AND (p_year IS NULL OR EXTRACT(YEAR FROM t.date_operation) = p_year)
                            AND (p_month IS NULL OR EXTRACT(MONTH FROM t.date_operation) = p_month)
                            -- Nota: Los valores de DOW son diferentes en PostgreSQL (Domingo=0, Lunes=1...)
                            AND (p_weekend = FALSE OR EXTRACT(DOW FROM t.date_operation) IN (0, 6))
                            AND (p_workday = FALSE OR EXTRACT(DOW FROM t.date_operation) BETWEEN 1 AND 5)
                            AND (p_type IS NULL OR t.type_transaction::type_transaction = p_type)
                            AND (p_amount IS NULL OR p_amount = 0.00 OR t.amount = p_amount)
                            AND (
                                p_search IS NULL 
                                OR d.name ILIKE '%' || p_search || '%' 
                                OR EXISTS (
                                    SELECT 1
                                    FROM transaction_yapes AS yape_trans
                                    WHERE yape_trans.amount = t.amount
                                    AND yape_trans.date_operation::date = t.date_operation::date
                                    AND yape_trans.destination ILIKE '%' || p_search || '%'
                                )
                            )
                            AND CASE
                                    WHEN p_category = 'without_sub_category' THEN t.category_id IS NULL
                                    WHEN p_category IS NOT NULL THEN t.category_id = p_category::INT -- Puede ser necesario un cast
                                    ELSE TRUE
                                END
                        GROUP BY t.detail_id, d.name -- Es buena práctica incluir todas las columnas no agregadas en el GROUP BY
                    )
                    SELECT 
                        fd.*,
                        COUNT(*) OVER() AS total_count
                    FROM 
                        FilteredData fd
                    ORDER BY 
                        CASE WHEN p_recurring = TRUE THEN fd.frequency END DESC NULLS LAST
                    LIMIT p_per_page 
                    OFFSET (p_page - 1) * p_per_page;
                END;
                $$
                ;
        SQL;
        DB::unprepared($sql);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $sql = "DROP FUNCTION IF EXISTS public.get_transactions_by_detail(
                    integer, 
                    integer, 
                    integer, 
                    integer, 
                    type_transaction, 
                    numeric, 
                    character varying, 
                    character varying, 
                    integer, 
                    boolean, 
                    boolean, 
                    boolean
                );";
        DB::unprepared($sql);
    }
};
