<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Removes `get_pareto_monthly_report`, whose rules now live in
     * `App\Services\Pareto\ParetoReportBuilder`.
     *
     * [ADR-0009](../../../docs/decisions/0009-coach-narrates-does-not-advise.md)
     * records the consequence this pays off: Financial Analysis rules have to leave
     * PostgreSQL, because a stored procedure returns 82% and cannot be asked why.
     * The trigger was `categories.budget_period`. Teaching the function that a yearly
     * envelope weighs a twelfth in the distribution and nothing in the pace would have
     * meant adding domain logic to plpgsql, which this repository's rules forbid by
     * name.
     *
     * DROPPED rather than left in place uncalled. A function nobody calls is still a
     * rule living in the database, and the next reader has no way to know which of the
     * two definitions of "the Pareto report" is the real one.
     *
     * `down()` restores the last shipped definition — the 2026-04-26 one, flat
     * `monthly_budget` sums and all — so rolling back lands on a database the previous
     * application code can actually run against.
     */
    public function up(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS get_pareto_monthly_report(integer, integer, integer, integer, integer);');
    }

    public function down(): void
    {
        $sql = <<<SQL
            CREATE OR REPLACE FUNCTION get_pareto_monthly_report (
                p_user_id integer,
                p_month integer,
                p_year integer,
                p_page integer,
                p_per_page integer
            )
            RETURNS TABLE (
                    id bigint,
                    name text,
                    percentage numeric,
                    actual_percentage numeric,
                    monthly_budget numeric,
                    spent numeric,
                    available_budget numeric,
                    percentage_spent numeric,
                    categories jsonb,
                    total_income numeric,
                    total_expense numeric,
                    total_records bigint
            )
            LANGUAGE plpgsql

            AS \$\$
            DECLARE
                v_offset INT;
                v_total_income NUMERIC;
                v_total_expense NUMERIC;
                v_total_budget_all NUMERIC;
            BEGIN

                v_offset := (p_page - 1) * p_per_page;

                SELECT
                    SUM(CASE WHEN mut.type_transaction = 'income' THEN mut.amount ELSE 0 END),
                    SUM(CASE WHEN mut.type_transaction = 'expense' THEN mut.amount ELSE 0 END)
                INTO v_total_income, v_total_expense
                FROM v_unified_transactions mut
                WHERE mut.user_id = p_user_id
                AND (p_year IS NULL OR EXTRACT(YEAR FROM mut.date_operation) = p_year)
                AND (p_month IS NULL OR EXTRACT(MONTH FROM mut.date_operation) = p_month);

                SELECT SUM(c.monthly_budget) INTO v_total_budget_all
                FROM categories c
                WHERE c.user_id = p_user_id
                AND c.type != 'income'
                AND (c.parent_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM categories c2 WHERE c2.parent_id = c.id));

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
                    (p_year IS NULL OR EXTRACT (YEAR FROM mut.date_operation) = p_year) AND
                    (p_month IS NULL OR EXTRACT (MONTH FROM mut.date_operation) = p_month) AND
                    mut.user_id = p_user_id
                    GROUP BY mut.category_id
                ),
                category_summaries AS (
                    SELECT
                        c.id,
                        cpa.pareto_classification_id,
                        c.name,
                        c.monthly_budget,
                        c.type,
                        COALESCE(tmbc.total_spent, 0) AS spent
                    FROM categories c
                    LEFT JOIN category_pareto_assignments cpa ON cpa.category_id = c.id
                    LEFT JOIN transaction_montly_by_category tmbc ON tmbc.category_id = c.id
                    WHERE c.user_id = p_user_id
                    AND c.type != 'income'
                    AND (c.parent_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM categories c2 WHERE c2.parent_id = c.id))
                ),
                pareto_summaries AS (
                    SELECT
                        pc.id,
                        pc.name,
                        pc.percentage,
                        SUM(COALESCE(cs.monthly_budget, 0)) AS total_monthly_budget,
                        SUM(COALESCE(cs.spent, 0)) AS total_spent,
                        JSONB_AGG(
                            JSONB_BUILD_OBJECT(
                                'id', cs.id,
                                'name', cs.name,
                                'monthly_budget', cs.monthly_budget,
                                'spent', cs.spent,
                                'type', cs.type
                            ) ORDER BY cs.monthly_budget DESC
                        ) FILTER (WHERE cs.id IS NOT NULL) AS categories
                    FROM pareto_classifications pc
                    LEFT JOIN category_summaries cs ON cs.pareto_classification_id = pc.id
                    WHERE pc.user_id = p_user_id
                    GROUP BY pc.id, pc.name, pc.percentage
                )
                SELECT
                    ps.id,
                    ps.name::TEXT,
                    ps.percentage::NUMERIC,
                    CASE
                        WHEN COALESCE(v_total_budget_all, 0) = 0 THEN 0
                        ELSE ROUND((ps.total_monthly_budget * 100.0) / v_total_budget_all, 2)
                    END::NUMERIC AS actual_percentage,
                    ps.total_monthly_budget::NUMERIC,
                    ps.total_spent::NUMERIC,
                    (ps.total_monthly_budget - ps.total_spent)::NUMERIC AS available_budget,
                    CASE
                        WHEN ps.total_monthly_budget = 0 THEN 0
                        ELSE ROUND((ps.total_spent * 100.0) / ps.total_monthly_budget, 2)
                    END::NUMERIC AS percentage_spent,
                    COALESCE(ps.categories, '[]'::jsonb) AS categories,
                    COALESCE(v_total_income, 0)::NUMERIC AS total_income,
                    COALESCE(v_total_expense, 0)::NUMERIC AS total_expense,
                    COUNT(*) OVER()::BIGINT AS total_records
                FROM pareto_summaries ps
                ORDER BY ps.id
                LIMIT p_per_page
                OFFSET v_offset;

            END;
            \$\$
            ;
SQL;
        DB::unprepared($sql);
    }
};
