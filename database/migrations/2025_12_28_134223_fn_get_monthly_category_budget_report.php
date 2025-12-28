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
                    CREATE OR REPLACE FUNCTION get_monthly_category_budget_report (
                        p_page integer,
                        p_per_page integer,
                        p_user_id integer,
                        p_month integer,
                        p_year integer
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
                            FROM mv_unified_transactions mut 
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
                                COALESCE(qbr.total_quantity, 0) AS total_quantity
                            FROM categories c
                            LEFT JOIN transaction_montly_by_category tmbc ON tmbc.category_id = c.id
                            LEFT JOIN quantity_by_rules qbr ON qbr.category_id = c.id
                            WHERE c.user_id = p_user_id
                            AND c.parent_id IS NOT NULL
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
                        ORDER BY fc.percentage_spent DESC, monthly_budget DESC
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
        DB::unprepared('DROP FUNCTION IF EXISTS get_monthly_category_budget_report(integer, integer, integer, integer, integer);');
    }
};
