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
                            monthly_budget numeric,
                            spent numeric,
                            available_budget numeric,
                            percentage_spent numeric,
                            category_names jsonb,
                            total_income numeric,
                            total_expense numeric,
                            total_records bigint
                    )
                    LANGUAGE plpgsql

                    AS $$
                    DECLARE
                        v_offset INT;
                        v_total_income NUMERIC;
                        v_total_expense NUMERIC;
                    BEGIN

                        v_offset := (p_page - 1) * p_per_page;

                        -- Calculate totals for the month
                        SELECT 
                            SUM(CASE WHEN mut.type_transaction = 'income' THEN mut.amount ELSE 0 END),
                            SUM(CASE WHEN mut.type_transaction = 'expense' THEN mut.amount ELSE 0 END)
                        INTO v_total_income, v_total_expense
                        FROM v_unified_transactions mut
                        WHERE mut.user_id = p_user_id
                        AND (p_year IS NULL OR EXTRACT(YEAR FROM mut.date_operation) = p_year)
                        AND (p_month IS NULL OR EXTRACT(MONTH FROM mut.date_operation) = p_month);

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
                                c.pareto_classification_id,
                                c.name,
                                c.monthly_budget,
                                COALESCE(tmbc.total_spent, 0) AS spent
                            FROM categories c
                            LEFT JOIN transaction_montly_by_category tmbc ON tmbc.category_id = c.id
                            WHERE c.user_id = p_user_id
                            AND (c.parent_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM categories c2 WHERE c2.parent_id = c.id))
                        ),
                        pareto_summaries AS (
                            SELECT
                                pc.id,
                                pc.name,
                                pc.percentage,
                                SUM(COALESCE(cs.monthly_budget, 0)) AS total_monthly_budget,
                                SUM(COALESCE(cs.spent, 0)) AS total_spent,
                                JSONB_AGG(cs.name) FILTER (WHERE cs.name IS NOT NULL) AS category_names
                            FROM pareto_classifications pc
                            LEFT JOIN category_summaries cs ON cs.pareto_classification_id = pc.id
                            WHERE pc.user_id = p_user_id
                            GROUP BY pc.id, pc.name, pc.percentage
                        )
                        SELECT 
                            ps.id,
                            ps.name::TEXT,
                            ps.percentage::NUMERIC,
                            ps.total_monthly_budget::NUMERIC,
                            ps.total_spent::NUMERIC,
                            (ps.total_monthly_budget - ps.total_spent)::NUMERIC AS available_budget,
                            CASE
                                WHEN ps.total_monthly_budget = 0 THEN 0
                                ELSE ROUND((ps.total_spent * 100.0) / ps.total_monthly_budget, 2)
                            END::NUMERIC AS percentage_spent,
                            COALESCE(ps.category_names, '[]'::jsonb) AS category_names,
                            COALESCE(v_total_income, 0)::NUMERIC AS total_income,
                            COALESCE(v_total_expense, 0)::NUMERIC AS total_expense,
                            COUNT(*) OVER()::BIGINT AS total_records
                        FROM pareto_summaries ps
                        ORDER BY id
                        LIMIT p_per_page
                        OFFSET v_offset;

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
        DB::unprepared('DROP FUNCTION IF EXISTS get_pareto_monthly_report(integer, integer, integer, integer, integer);');
    }
};
