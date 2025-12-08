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
                    CREATE OR REPLACE FUNCTION get_transactions(p_per_page integer, p_page integer, p_year integer, p_month integer, p_type type_transaction, p_amount numeric, p_search character varying, p_category text, p_user_id integer, p_recurring boolean, p_weekend boolean, p_workday boolean)
                    RETURNS TABLE(id bigint, message text, amount numeric, date_operation character varying, type_transaction character varying, category_id bigint, detail_id bigint, detail_name character varying, frequency_general jsonb, frequency bigint, yape_trans jsonb, yape_id bigint, user_id bigint, source_type text, suggested_category_id bigint, suggest_name character varying, tags jsonb, total_count bigint)
                    LANGUAGE sql
                    AS $$
                                    SELECT * FROM (
                                        WITH RECURRING_CATEGORY_FREQUENCIES AS (
                                            SELECT
                                                d.id AS detail_id,
                                                JSONB_AGG(
                                                    JSONB_BUILD_OBJECT(
                                                        'count', sub.count,
                                                        'name', sub.name
                                                    )
                                                ) AS frequency_general_json
                                            FROM details d
                                            JOIN (
                                                SELECT
                                                    t2.detail_id,
                                                    c.name,
                                                    COUNT(t2.id) AS count
                                                FROM transactions t2
                                                JOIN categories c ON c.id = t2.category_id
                                                GROUP BY t2.detail_id, c.name
                                            ) sub ON sub.detail_id = d.id
                                            GROUP BY d.id
                                        ),
                                        TAGS_BY_TRANSACTION AS (
                                            SELECT tt.transaction_id , tt.transaction_yape_id, jsonb_agg(
                                                jsonb_build_object(
                                                    'tag', t."name" 
                                                )
                                            ) tags FROM transaction_tag tt 
                                            JOIN tags t ON t.id = tt.tag_id 
                                            LEFT JOIN transaction_yapes ty ON ty.id = tt.transaction_yape_id 
                                            LEFT JOIN transactions t2 ON t2.id = tt.transaction_id 
                                            GROUP BY tt.transaction_id , tt.transaction_yape_id
                                        ),
                                        PRE_FILTERED_TRANSACTIONS AS (
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
                                                dsf.frequency_general_json AS frequency_general,
                                                COUNT(t.id) OVER(PARTITION BY t.detail_id) AS frequency,
                                                tbt.tags
                                            FROM transactions t
                                            JOIN details d ON d.id = t.detail_id
                                            LEFT JOIN RECURRING_CATEGORY_FREQUENCIES dsf ON dsf.detail_id = t.detail_id
                                            LEFT JOIN TAGS_BY_TRANSACTION tbt ON tbt.transaction_id = t.id
                                            WHERE
                                                t.user_id = p_user_id
                                                AND (p_year IS NULL OR EXTRACT(YEAR FROM t.date_operation) = p_year)
                                                AND (p_month IS NULL OR EXTRACT(MONTH FROM t.date_operation) = p_month)
                                                AND (p_weekend = FALSE OR EXTRACT(DOW FROM t.date_operation) IN (0, 6))
                                                AND (p_workday = FALSE OR EXTRACT(DOW FROM t.date_operation) BETWEEN 1 AND 5)
                                                AND (p_type IS NULL OR t.type_transaction::type_transaction = p_type)
                                                AND (p_amount IS NULL OR p_amount = 0.00 OR t.amount = p_amount)
                                                AND (CASE
                                                        WHEN p_category = 'without_category' THEN t.category_id IS NULL
                                                        WHEN p_category IS NOT NULL AND p_category <> 'without_category' THEN t.category_id = p_category::BIGINT -- Casting necesario
                                                        ELSE TRUE
                                                    END)
                                        ),
                                        PRE_FILTERED_YAPES AS (
                                            SELECT
                                                ty.id, ty.message, ty.amount, ty.date_operation, ty.type_transaction,
                                                d.description detail_name, d.id detail_id, ty.user_id, ty.category_id, ty.suggested_category_id, 
                                                c.name suggest_name, tbt.tags
                                            FROM transaction_yapes ty
                                            JOIN details d ON d.id = ty.detail_id
                                            LEFT JOIN categories c ON c.id = ty.suggested_category_id
                                            LEFT JOIN TAGS_BY_TRANSACTION tbt ON tbt.transaction_yape_id = ty.id
                                            WHERE
                                                ty.user_id = p_user_id
                                                AND (p_year IS NULL OR EXTRACT(YEAR FROM ty.date_operation) = p_year)
                                                AND (p_month IS NULL OR EXTRACT(MONTH FROM ty.date_operation) = p_month)
                                                AND (p_weekend = FALSE OR EXTRACT(DOW FROM ty.date_operation) IN (0, 6))
                                                AND (p_workday = FALSE OR EXTRACT(DOW FROM ty.date_operation) BETWEEN 1 AND 5)
                                                AND (p_type IS NULL OR ty.type_transaction::type_transaction = p_type)
                                                AND (p_amount IS NULL OR p_amount = 0.00 OR ty.amount = p_amount)
                                                AND (CASE
                                                        WHEN p_category = 'without_category' THEN ty.category_id IS NULL
                                                        WHEN p_category IS NOT NULL AND p_category <> 'without_category' THEN ty.category_id = p_category::BIGINT
                                                        ELSE TRUE
                                                    END)
                                        ),
                                        YAPE_DETAILS_AGGREGATED AS (
                                            SELECT
                                                ty.date_operation::DATE AS op_date,
                                                ty.amount,
                                                ty.user_id,
                                                JSONB_AGG(
                                                    JSONB_BUILD_OBJECT(
                                                        'date_operation', ty.date_operation,
                                                        'amount', ty.amount,
                                                        'detail_name', ty.detail_name,
                                                        'type_transaction', ty.type_transaction,
                                                        'message', ty.message
                                                    )
                                                ) AS yape_trans_json
                                            FROM PRE_FILTERED_YAPES ty
                                            GROUP BY ty.date_operation::DATE, ty.amount, ty.user_id
                                        ),
                                        TRANSACTIONS_YAPE_LINK_CANDIDATES AS (
                                            -- Emparejar transacciones con yape_trans (similar a pair_possible)
                                            SELECT
                                                pft.*,
                                                yda.yape_trans_json AS yape_trans,
                                                pfy.id AS matched_yape_id,
                                                ROW_NUMBER() OVER (PARTITION BY pft.id ORDER BY pfy.id ASC) AS rn
                                            FROM PRE_FILTERED_TRANSACTIONS pft
                                            LEFT JOIN PRE_FILTERED_YAPES pfy -- Para matched_yape_id
                                                ON pft.date_operation::DATE = pfy.date_operation::DATE
                                                AND pft.amount = pfy.amount
                                                AND pft.user_id = pfy.user_id
                                            LEFT JOIN YAPE_DETAILS_AGGREGATED yda -- Para yape_trans (JSON agregado)
                                                ON pft.date_operation::DATE = yda.op_date
                                                AND pft.amount = yda.amount
                                                AND pft.user_id = yda.user_id
                                        ),
                                        UNIQUE_TRANSACTION_MATCHES AS (
                                            -- Transacciones únicas emparejadas o no emparejadas, con filtro de búsqueda aplicado
                                            SELECT
                                                tylc.id, tylc.message, tylc.amount, tylc.date_operation, tylc.type_transaction,
                                                tylc.category_id, tylc.detail_id, tylc.detail_name, tylc.frequency_general,
                                                tylc.frequency, tylc.yape_trans, tylc.matched_yape_id, tylc.user_id,
                                                'transaction' AS source_type, NULL::BIGINT AS suggested_category_id, 
                                                NULL AS suggest_name, tylc.tags
                                            FROM TRANSACTIONS_YAPE_LINK_CANDIDATES tylc
                                            WHERE
                                                tylc.rn = 1
                                                AND ( -- Condición de búsqueda
                                                    p_search IS NULL OR p_search = '' OR
                                                    tylc.detail_name ILIKE ('%' || p_search || '%') OR -- ILIKE para búsqueda insensible a mayúsculas
                                                    EXISTS (
                                                        SELECT 1
                                                        FROM PRE_FILTERED_YAPES search_yapes
                                                        WHERE search_yapes.amount = tylc.amount
                                                        AND search_yapes.date_operation::DATE = tylc.date_operation::DATE
                                                        AND search_yapes.user_id = tylc.user_id
                                                        AND search_yapes.detail_name ILIKE ('%' || p_search || '%')
                                                    )
                                                )
                                        ),
                                        FREQUENCY_YAPE AS (
                                            SELECT 
                                                ty.detail_id, 
                                                JSONB_AGG(
                                                    JSONB_BUILD_OBJECT(
                                                        'date_operation', ty.date_operation,
                                                        'category', c.name
                                                    )
                                                ) AS frequency_general,
                                                COUNT(*) AS frequency
                                            FROM transaction_yapes ty
                                            LEFT JOIN categories c on c.id = ty.category_id
                                            GROUP BY ty.detail_id
                                            HAVING COUNT(*) <= 5 AND COUNT(*) > 1
                                        ),
                                        YAPES_WITHOUT_MATCH AS (
                                            SELECT
                                                pfy.id, pfy.message, pfy.amount, pfy.date_operation, pfy.type_transaction,
                                                pfy.category_id,
                                                pfy.detail_id AS detail_id,
                                                pfy.detail_name AS detail_name,
                                                fy.frequency_general AS frequency_general,
                                                fy.frequency AS frequency,
                                                NULL::JSONB AS yape_trans,
                                                pfy.id AS matched_yape_id,
                                                pfy.user_id,
                                                'yape_unmatched' AS source_type,
                                                pfy.suggested_category_id,
                                                pfy.suggest_name,
                                                pfy.tags
                                            FROM PRE_FILTERED_YAPES pfy
                                            LEFT JOIN FREQUENCY_YAPE fy ON fy.detail_id = pfy.detail_id
                                            WHERE
                                                NOT EXISTS (
                                                    SELECT 1
                                                    FROM UNIQUE_TRANSACTION_MATCHES utm
                                                    WHERE utm.matched_yape_id = pfy.id
                                                )
                                                AND (p_search IS NULL OR p_search = '' OR pfy.detail_name ILIKE ('%' || p_search || '%'))
                                        ),
                                        FINAL_UNION_WITH_TOTAL_COUNT AS (
                                            SELECT * FROM UNIQUE_TRANSACTION_MATCHES
                                            UNION ALL
                                            SELECT * FROM YAPES_WITHOUT_MATCH
                                        ),
                                        ORDER_ALL_TRANSACTION as (
                                            SELECT * FROM FINAL_UNION_WITH_TOTAL_COUNT fuwtc
                                            ORDER BY
                                                    CASE WHEN p_recurring = TRUE THEN fuwtc.frequency ELSE NULL END DESC,
                                                    fuwtc.date_operation DESC
                                        )
                                        SELECT
                                            oat.id, 
                                            oat.message,
                                            oat.amount, TO_CHAR(
                                            oat.date_operation::timestamp, 
                                            'Dy DD Mon YYYY HH12:MI AM'
                                            ) date_operation, 
                                            oat.type_transaction,
                                            oat.category_id, oat.detail_id, oat.detail_name,
                                            oat.frequency_general, oat.frequency, oat.yape_trans,
                                            oat.matched_yape_id AS yape_id,
                                            oat.user_id,
                                            oat.source_type,
                                            oat.suggested_category_id,
                                            oat.suggest_name,
                                            oat.tags,
                                            COUNT(*) OVER() AS total_count
                                        FROM ORDER_ALL_TRANSACTION oat
                                        LIMIT p_per_page
                                        OFFSET (p_page - 1) * p_per_page
                                    ) AS final_result_set;
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
        $sql = "DROP FUNCTION IF EXISTS get_transactions(int4, int4, int4, int4, type_transaction, numeric, varchar, text, int4, bool, bool, bool);";
        DB::unprepared($sql);
    }
};
