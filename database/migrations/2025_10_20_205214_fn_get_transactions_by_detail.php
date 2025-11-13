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
                        CREATE OR REPLACE FUNCTION get_transactions_by_detail(p_per_page integer, p_page integer, p_year integer, p_month integer, p_type type_transaction, p_amount numeric, p_search character varying, p_category character varying, p_user_id integer, p_recurring boolean, p_weekend boolean, p_workday boolean)
                        RETURNS TABLE(detail_id bigint, detail_name character varying, child_transactions jsonb, frequency bigint, amount numeric, total_count bigint)
                        LANGUAGE plpgsql
                        AS $$
                            BEGIN
                                RETURN QUERY
                                WITH MatchedYapes AS (
                                    SELECT 
                                        t.id AS transaction_id,
                                        jsonb_agg(
                                            jsonb_build_object(
                                                'date_operation', yape.date_operation,
                                                'amount', yape.amount,
                                                'detail_name', d_yape.description,
                                                'type_transaction', yape.type_transaction,
                                                'message', yape.message
                                            )
                                        ) AS yape_trans_json
                                    FROM 
                                        transactions AS t
                                    JOIN 
                                        transaction_yapes AS yape 
                                        ON yape.amount = t.amount 
                                        AND yape.date_operation::date = t.date_operation::date
                                        AND yape.user_id = t.user_id
                                    JOIN 
                                        details AS d_yape ON d_yape.id = yape.detail_id
                                    WHERE 
                                        t.user_id = p_user_id
                                    GROUP BY 
                                        t.id
                                ),
                                UnmatchedYapes AS (
                                    SELECT 
                                        ty.*
                                    FROM 
                                        transaction_yapes AS ty
                                    LEFT JOIN 
                                        transactions AS t 
                                        ON ty.amount = t.amount 
                                        AND ty.date_operation::date = t.date_operation::date
                                        AND ty.user_id = t.user_id
                                    WHERE 
                                        ty.user_id = p_user_id
                                        AND t.id IS NULL
                                ),
                                AllData AS (
                                    SELECT 
                                        t.id, t.detail_id, t.message, t.amount, t.date_operation, 
                                        t.type_transaction, t.category_id,
                                        'transaction' AS source_type,
                                        COALESCE(my.yape_trans_json, '[]'::jsonb) AS yape_trans,
                                        t.user_id, null::BIGINT AS suggested_category_id
                                    FROM 
                                        transactions AS t
                                    LEFT JOIN 
                                        MatchedYapes AS my ON my.transaction_id = t.id
                                    WHERE 
                                        t.user_id = p_user_id
                                        AND CASE
                                            WHEN p_category = 'without_category' THEN t.category_id IS NULL
                                            WHEN p_category IS NOT NULL AND p_category <> 'without_category' THEN t.category_id = p_category::BIGINT
                                            ELSE TRUE
                                        END
                                    UNION ALL
                                    SELECT 
                                        uy.id, uy.detail_id, uy.message, uy.amount, uy.date_operation, 
                                        uy.type_transaction, uy.category_id,
                                        'yape_unmatched' AS source_type,
                                        '[]'::jsonb AS yape_trans,
                                        uy.user_id,
                                        uy.suggested_category_id
                                    FROM 
                                        UnmatchedYapes AS uy
                                    WHERE CASE
                                        WHEN p_category = 'without_category' THEN uy.category_id IS NULL
                                        WHEN p_category IS NOT NULL AND p_category <> 'without_category' THEN uy.category_id = p_category::BIGINT
                                        ELSE TRUE
                                    END
                                ),
                                FilteredData AS (
                                    SELECT 
                                        a.detail_id,
                                        d.description AS detail_name,
                                        jsonb_agg(
                                            jsonb_build_object(
                                                'id', a.id,
                                                'message', a.message,
                                                'amount', a.amount,
                                                'date_operation', TO_CHAR(
                                                    a.date_operation::timestamp, 
                                                    'Dy DD Mon YYYY HH12:MI AM'
                                                ),
                                                'type_transaction', a.type_transaction,
                                                'category_id', a.category_id,
                                                'detail_id', a.detail_id,
                                                'detail_name', d.description,
                                                'yape_trans', a.yape_trans,
                                                'source_type', a.source_type,
                                                'suggest_name', c.name
                                            )
                                        ) AS child_transactions,
                                        COUNT(a.id) AS frequency,
                                        SUM(CASE WHEN a.type_transaction::type_transaction = 'income' THEN a.amount ELSE 0 END) 
                                        - SUM(CASE WHEN a.type_transaction::type_transaction = 'expense' THEN a.amount ELSE 0 END) AS amount
                                    FROM 
                                        AllData AS a
                                    JOIN 
                                        details AS d ON d.id = a.detail_id
                                    LEFT JOIN categories c ON c.id = a.suggested_category_id
                                    WHERE 
                                    a.user_id = p_user_id
                                    AND (p_year IS NULL OR EXTRACT(YEAR FROM a.date_operation) = p_year)
                                    AND (p_month IS NULL OR EXTRACT(MONTH FROM a.date_operation) = p_month)
                                    AND (p_weekend = FALSE OR EXTRACT(DOW FROM a.date_operation) IN (0, 6))
                                    AND (p_workday = FALSE OR EXTRACT(DOW FROM a.date_operation) BETWEEN 1 AND 5)
                                    AND (p_type IS NULL OR a.type_transaction::type_transaction = p_type)
                                    AND (p_amount IS NULL OR p_amount = 0.00 OR a.amount = p_amount)
                                    AND (
                                        p_search IS NULL 
                                        OR d.description ILIKE '%' || p_search || '%' 
                                        OR EXISTS (
                                            SELECT 1
                                            FROM transaction_yapes AS yape_trans
                                            JOIN details d ON d.id = yape_trans.detail_id
                                            WHERE yape_trans.amount = a.amount
                                            AND yape_trans.date_operation::date = a.date_operation::date
                                            AND d.description ILIKE '%' || p_search || '%'
                                        )
                                    )
                                    GROUP BY 
                                        a.detail_id, d.description
                                    having COUNT(a.id) > 1
                                )
                                SELECT 
                                    fd.*,
                                    COUNT(*) OVER() AS total_count
                                FROM 
                                    FilteredData fd
                                ORDER BY 
                                    fd.frequency DESC NULLS LAST
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
