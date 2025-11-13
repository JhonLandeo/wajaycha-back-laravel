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
                    RETURNS TABLE(id bigint, message text, amount numeric, date_operation character varying, type_transaction character varying, category_id bigint, detail_id bigint, detail_name character varying, frequency_general jsonb, frequency bigint, yape_trans jsonb, yape_id bigint, user_id bigint, source_type text, suggested_category_id bigint, suggest_name character varying, total_count bigint)
                    LANGUAGE sql
                    AS $$
                                            SELECT * FROM (
                                                WITH RECURRING_CATEGORY_FREQUENCIES AS (
                                                    -- CTE para calcular la frecuencia general de categorías por detail_id (equivalente a detail_category_frequencies)
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
                                                PRE_FILTERED_TRANSACTIONS AS (
                                                    -- CTE para transacciones filtradas base
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
                                                        COUNT(t.id) OVER(PARTITION BY t.detail_id) AS frequency
                                                    FROM transactions t
                                                    JOIN details d ON d.id = t.detail_id
                                                    LEFT JOIN RECURRING_CATEGORY_FREQUENCIES dsf ON dsf.detail_id = t.detail_id
                                                    WHERE
                                                        t.user_id = p_user_id
                                                        -- Filtros de Fecha (YEAR, MONTH)
                                                        AND (p_year IS NULL OR EXTRACT(YEAR FROM t.date_operation) = p_year)
                                                        AND (p_month IS NULL OR EXTRACT(MONTH FROM t.date_operation) = p_month)
                                                        -- Filtros de Día (DAYOFWEEK en MySQL es 1=Dom, 7=Sab. En PG WEEKDAY es 0=Dom, 6=Sab)
                                                        -- Ajustamos para la lógica de PG (0=Dom, 6=Sab. FDS = 0 o 6)
                                                        AND (p_weekend = FALSE OR EXTRACT(DOW FROM t.date_operation) IN (0, 6))
                                                        -- Días laborables (2=Lun a 6=Vie en MySQL; 1=Lun a 5=Vie en PG)
                                                        AND (p_workday = FALSE OR EXTRACT(DOW FROM t.date_operation) BETWEEN 1 AND 5)
                                                        
                                                        -- Filtros de Tipo y Monto
                                                        AND (p_type IS NULL OR t.type_transaction::type_transaction = p_type)
                                                        AND (p_amount IS NULL OR p_amount = 0.00 OR t.amount = p_amount)
                                                        
                                                        -- Filtros de categoría
                                                        AND (CASE
                                                                WHEN p_category = 'without_category' THEN t.category_id IS NULL
                                                                WHEN p_category IS NOT NULL AND p_category <> 'without_category' THEN t.category_id = p_category::BIGINT -- Casting necesario
                                                                ELSE TRUE
                                                            END)
                                                ),
                                                PRE_FILTERED_YAPES AS (
                                                    -- CTE para transaction_yapes filtradas base
                                                    SELECT
                                                        ty.id, ty.message, ty.amount, ty.date_operation, ty.type_transaction,
                                                        d.description detail_name, ty.user_id, ty.category_id, ty.suggested_category_id, c.name suggest_name
                                                    FROM transaction_yapes ty
                                                    JOIN details d ON d.id = ty.detail_id
                                                    LEFT JOIN categories c ON c.id = ty.suggested_category_id
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
                                                    -- CTE para pre-agregar yape_trans en formato JSON
                                                    SELECT
                                                        ty.date_operation::DATE AS op_date, -- Castear a DATE para agrupar
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
                                                        'transaction' AS source_type, NULL::BIGINT AS suggested_category_id, NULL AS suggest_name
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
                                                YAPES_WITHOUT_MATCH AS (
                                                    -- Yapes que no se emparejaron con ninguna transacción
                                                    SELECT
                                                        pfy.id, pfy.message, pfy.amount, pfy.date_operation, pfy.type_transaction,
                                                        pfy.category_id,
                                                        NULL::BIGINT AS detail_id,
                                                        pfy.detail_name AS detail_name,
                                                        NULL::JSONB AS frequency_general,
                                                        NULL::BIGINT AS frequency,
                                                        NULL::JSONB AS yape_trans,
                                                        pfy.id AS matched_yape_id,
                                                        pfy.user_id,
                                                        'yape_unmatched' AS source_type,
                                                        pfy.suggested_category_id,
                                                        pfy.suggest_name
                                                    FROM PRE_FILTERED_YAPES pfy
                                                    WHERE
                                                        NOT EXISTS (
                                                            SELECT 1
                                                            FROM UNIQUE_TRANSACTION_MATCHES utm
                                                            WHERE utm.matched_yape_id = pfy.id
                                                        )
                                                        AND (p_search IS NULL OR p_search = '' OR pfy.detail_name ILIKE ('%' || p_search || '%'))
                                                ),
                                                FINAL_UNION_WITH_TOTAL_COUNT AS (
                                                    -- Unir ambos conjuntos de resultados
                                                    SELECT * FROM UNIQUE_TRANSACTION_MATCHES
                                                    UNION ALL
                                                    SELECT * FROM YAPES_WITHOUT_MATCH
                                                )
                                                -- Consulta Final con Paginación y Conteo Total
                                                SELECT
                                                    fuwtc.id, fuwtc.message, fuwtc.amount, TO_CHAR(
                                                        fuwtc.date_operation::timestamp, 
                                                        'Dy DD Mon YYYY HH12:MI AM'
                                                    ) date_operation, fuwtc.type_transaction,
                                                    fuwtc.category_id, fuwtc.detail_id, fuwtc.detail_name,
                                                    fuwtc.frequency_general, fuwtc.frequency, fuwtc.yape_trans,
                                                    fuwtc.matched_yape_id AS yape_id,
                                                    fuwtc.user_id,
                                                    fuwtc.source_type,
                                                    fuwtc.suggested_category_id,
                                                    fuwtc.suggest_name,
                                                    COUNT(*) OVER() AS total_count -- Conteo total sin paginar
                                                FROM FINAL_UNION_WITH_TOTAL_COUNT fuwtc
                                                ORDER BY
                                                    CASE WHEN p_recurring = TRUE THEN fuwtc.frequency ELSE NULL END DESC,
                                                    fuwtc.date_operation DESC
                                                LIMIT COALESCE(p_per_page, 1000000) -- Si es NULL, usa un número gigante
                                                OFFSET COALESCE(p_page - 1, 0) * COALESCE(p_per_page, 1000000)
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
