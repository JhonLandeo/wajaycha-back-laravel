<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `get_monthly_category_budget_report` pasa a leer `v_transaction_allocations`
     * en vez de `v_unified_transactions.category_id`.
     *
     * Es el lector más importante de la Fase 1: alimenta el reporte mensual de
     * presupuesto, los snapshots del coach y la desviación que usa el plan de
     * cobro. Si una transacción dividida no se leyera acá, el gasto de cada
     * categoría quedaría corto justo en el caso que motivó el reparto.
     *
     * La agregación no cambia de forma: `SUM(CASE ...)` sobre `allocated_amount`
     * en lugar de `amount`. La vista ya entrega una fila por categoría, así que
     * agrupar por `category_id` sigue siendo correcto. El filtro
     * `category_id IS NOT NULL` evita un grupo NULL que nunca se uniría a
     * `categories` y solo agregaría trabajo.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION get_monthly_category_budget_report (
                p_page integer,
                p_per_page integer,
                p_user_id integer,
                p_month integer,
                p_year integer,
                p_search text DEFAULT NULL
            )
            RETURNS TABLE (
                    id bigint,
                    name text,
                    monthly_budget numeric,
                    spent numeric,
                    available_budget numeric,
                    percentage_spent numeric,
                    rule_quantity bigint,
                    total_records bigint
            )
            LANGUAGE plpgsql

            AS $$
            DECLARE
                v_offset INT;
            BEGIN

                v_offset := (p_page - 1) * p_per_page;

                RETURN QUERY
                WITH transaction_montly_by_category AS (
                    SELECT
                        SUM(CASE
                            WHEN a.type_transaction = 'expense' THEN a.allocated_amount
                            WHEN a.type_transaction = 'income' THEN -a.allocated_amount
                            ELSE 0
                        END) AS total_spent,
                        a.category_id
                    FROM v_transaction_allocations a
                    WHERE
                    EXTRACT (YEAR FROM a.date_operation) = p_year AND
                    EXTRACT (MONTH FROM a.date_operation) = p_month AND
                    a.user_id = p_user_id AND
                    a.category_id IS NOT NULL
                    GROUP BY a.category_id
                ),
                quantity_by_rules AS (
                    SELECT
                        COUNT(*) AS total_quantity,
                        cr.category_id
                    FROM categorization_rules cr
                    WHERE cr.user_id = p_user_id
                    GROUP BY cr.category_id
                ),
                final_categories AS (
                    SELECT
                        c.id,
                        c.name,
                        c.monthly_budget,
                        COALESCE(tmbc.total_spent, 0) AS spent,
                        (c.monthly_budget - COALESCE(tmbc.total_spent, 0)) AS available_budget,
                        CASE
                            WHEN c.monthly_budget = 0 THEN 0
                            ELSE ROUND((COALESCE(tmbc.total_spent, 0) * 100.0) / c.monthly_budget, 2)
                        END AS percentage_spent,
                        COALESCE(qbr.total_quantity, 0) AS total_quantity,
                        c.created_at
                    FROM categories c
                    LEFT JOIN transaction_montly_by_category tmbc ON tmbc.category_id = c.id
                    LEFT JOIN quantity_by_rules qbr ON qbr.category_id = c.id
                    WHERE c.user_id = p_user_id
                    AND (c.parent_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM categories c2 WHERE c2.parent_id = c.id))
                    AND (p_search IS NULL OR p_search = '' OR c.name ILIKE '%' || p_search || '%')
                )
                SELECT
                    fc.id,
                    fc.name::TEXT,
                    fc.monthly_budget::NUMERIC,
                    fc.spent::NUMERIC,
                    fc.available_budget::NUMERIC,
                    fc.percentage_spent::NUMERIC,
                    fc.total_quantity::BIGINT,
                    COUNT(*) OVER()::BIGINT AS total_records
                FROM final_categories fc
                ORDER BY fc.percentage_spent DESC, monthly_budget DESC, total_quantity DESC, created_at DESC
                LIMIT p_per_page
                OFFSET v_offset;

            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        // Restaura la definición anterior: una fila por transacción, leyendo
        // `amount` desde `v_unified_transactions`.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION get_monthly_category_budget_report (
                p_page integer,
                p_per_page integer,
                p_user_id integer,
                p_month integer,
                p_year integer,
                p_search text DEFAULT NULL
            )
            RETURNS TABLE (
                    id bigint,
                    name text,
                    monthly_budget numeric,
                    spent numeric,
                    available_budget numeric,
                    percentage_spent numeric,
                    rule_quantity bigint,
                    total_records bigint
            )
            LANGUAGE plpgsql

            AS $$
            DECLARE
                v_offset INT;
            BEGIN

                v_offset := (p_page - 1) * p_per_page;

                RETURN QUERY
                WITH transaction_montly_by_category AS (
                    SELECT
                        SUM(CASE
                            WHEN mut.type_transaction = 'expense' THEN mut.amount
                            WHEN mut.type_transaction = 'income' THEN -mut.amount
                            ELSE 0
                        END) AS total_spent,
                        mut.category_id
                    FROM v_unified_transactions mut
                    WHERE
                    EXTRACT (YEAR FROM mut.date_operation) = p_year AND
                    EXTRACT (MONTH FROM mut.date_operation) = p_month AND
                    mut.user_id = p_user_id
                    GROUP BY mut.category_id
                ),
                quantity_by_rules AS (
                    SELECT
                        COUNT(*) AS total_quantity,
                        cr.category_id
                    FROM categorization_rules cr
                    WHERE cr.user_id = p_user_id
                    GROUP BY cr.category_id
                ),
                final_categories AS (
                    SELECT
                        c.id,
                        c.name,
                        c.monthly_budget,
                        COALESCE(tmbc.total_spent, 0) AS spent,
                        (c.monthly_budget - COALESCE(tmbc.total_spent, 0)) AS available_budget,
                        CASE
                            WHEN c.monthly_budget = 0 THEN 0
                            ELSE ROUND((COALESCE(tmbc.total_spent, 0) * 100.0) / c.monthly_budget, 2)
                        END AS percentage_spent,
                        COALESCE(qbr.total_quantity, 0) AS total_quantity,
                        c.created_at
                    FROM categories c
                    LEFT JOIN transaction_montly_by_category tmbc ON tmbc.category_id = c.id
                    LEFT JOIN quantity_by_rules qbr ON qbr.category_id = c.id
                    WHERE c.user_id = p_user_id
                    AND (c.parent_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM categories c2 WHERE c2.parent_id = c.id))
                    AND (p_search IS NULL OR p_search = '' OR c.name ILIKE '%' || p_search || '%')
                )
                SELECT
                    fc.id,
                    fc.name::TEXT,
                    fc.monthly_budget::NUMERIC,
                    fc.spent::NUMERIC,
                    fc.available_budget::NUMERIC,
                    fc.percentage_spent::NUMERIC,
                    fc.total_quantity::BIGINT,
                    COUNT(*) OVER()::BIGINT AS total_records
                FROM final_categories fc
                ORDER BY fc.percentage_spent DESC, monthly_budget DESC, total_quantity DESC, created_at DESC
                LIMIT p_per_page
                OFFSET v_offset;

            END;
            $$;
        SQL);
    }
};
