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
            CREATE OR REPLACE FUNCTION get_transactions(p_per_page integer, p_page integer, p_year integer, p_month integer, p_type type_transaction, p_amount numeric, p_search character varying, p_category text, p_user_id integer, p_recurring boolean, p_weekend boolean, p_workday boolean)
            RETURNS TABLE(id bigint, message text, amount numeric, date_operation character varying, type_transaction character varying, category_id bigint, detail_id bigint, detail_name character varying, frequency_general jsonb, frequency bigint, yape_trans jsonb, yape_id bigint, user_id bigint, source_type text, suggested_category_id bigint, suggest_name character varying, tags jsonb, is_manual boolean, total_count bigint)
            LANGUAGE sql
            AS $$
            SELECT * FROM (
                WITH RECONCILED_YAPES AS (
                    SELECT 
                        t_inner.matched_transaction_id,
                        jsonb_agg(
                            jsonb_build_object(
                                'date_operation', t_inner.date_operation,
                                'amount', t_inner.amount,
                                'detail_name', d_inner.description,
                                'type_transaction', t_inner.type_transaction,
                                'message', t_inner.message
                            )
                        ) as yape_trans_json,
                        MIN(t_inner.id) as first_yape_id
                    FROM transactions t_inner
                    JOIN details d_inner ON d_inner.id = t_inner.detail_id
                    WHERE t_inner.matched_transaction_id IS NOT NULL
                    GROUP BY t_inner.matched_transaction_id
                ),
                TAGS_BY_TRANSACTION AS (
                    SELECT tt.transaction_id, jsonb_agg(
                        jsonb_build_object(
                            'tag', tg."name" 
                        )
                    ) tags 
                    FROM transaction_tag tt 
                    JOIN tags tg ON tg.id = tt.tag_id 
                    GROUP BY tt.transaction_id
                ),
                ALL_TRANSACTIONS AS (
                    SELECT
                        t.id,
                        t.message,
                        t.amount,
                        t.date_operation,
                        t.type_transaction,
                        t.category_id,
                        t.detail_id,
                        d.description AS detail_name,
                        t.user_id,
                        t.source_type,
                        tbt.tags,
                        t.is_manual,
                        ry.yape_trans_json as yape_trans,
                        ry.first_yape_id as matched_yape_id
                    FROM transactions t
                    JOIN details d ON d.id = t.detail_id
                    LEFT JOIN TAGS_BY_TRANSACTION tbt ON tbt.transaction_id = t.id
                    LEFT JOIN RECONCILED_YAPES ry ON ry.matched_transaction_id = t.id
                    WHERE
                        t.user_id = p_user_id
                        AND t.matched_transaction_id IS NULL -- Only Master records
                        AND (p_year IS NULL OR EXTRACT(YEAR FROM t.date_operation) = p_year)
                        AND (p_month IS NULL OR EXTRACT(MONTH FROM t.date_operation) = p_month)
                        AND (p_weekend = FALSE OR EXTRACT(DOW FROM t.date_operation) IN (0, 6))
                        AND (p_workday = FALSE OR EXTRACT(DOW FROM t.date_operation) BETWEEN 1 AND 5)
                        AND (p_type IS NULL OR t.type_transaction::type_transaction = p_type)
                        AND (p_amount IS NULL OR p_amount = 0.00 OR t.amount = p_amount)
                        AND (CASE
                                WHEN p_category = 'without_category' THEN t.category_id IS NULL
                                WHEN p_category IS NOT NULL AND p_category <> 'without_category' THEN t.category_id = p_category::BIGINT
                                ELSE TRUE
                            END)
                        AND (p_search IS NULL OR p_search = '' OR d.description ILIKE ('%' || p_search || '%') OR t.message ILIKE ('%' || p_search || '%'))
                )
                SELECT
                    at.id, 
                    at.message,
                    at.amount, 
                    TO_CHAR(at.date_operation::timestamp, 'Dy DD Mon YYYY HH12:MI AM') as date_operation, 
                    at.type_transaction,
                    at.category_id, 
                    at.detail_id, 
                    at.detail_name,
                    NULL::jsonb as frequency_general,
                    0::bigint as frequency,
                    at.yape_trans,
                    at.matched_yape_id as yape_id,
                    at.user_id,
                    at.source_type,
                    NULL::bigint as suggested_category_id,
                    NULL::varchar as suggest_name,
                    at.tags,
                    at.is_manual,
                    COUNT(*) OVER() AS total_count
                FROM ALL_TRANSACTIONS at
                ORDER BY at.date_operation DESC
                LIMIT p_per_page
                OFFSET (p_page - 1) * p_per_page
            ) AS final_result_set;
            $$;
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
