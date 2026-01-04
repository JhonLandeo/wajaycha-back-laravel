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
                    CREATE OR REPLACE FUNCTION get_top_five_data(
                        p_user_id INT,
                        p_year INT DEFAULT NULL,
                        p_month INT DEFAULT NULL
                    )
                    RETURNS JSON AS $$
                    DECLARE
                        json_incomes JSON;
                        json_expenses JSON;
                    BEGIN
                        SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
                        INTO json_incomes
                        FROM (
                            SELECT 
                                detail_name as name, 
                                ROUND(SUM(amount)::numeric, 2) as value
                            FROM mv_unified_transactions
                            WHERE user_id = p_user_id
                            AND type_transaction = 'income'
                            AND (p_year IS NULL OR EXTRACT(YEAR FROM date_operation) = p_year)
                            AND (p_month IS NULL OR EXTRACT(MONTH FROM date_operation) = p_month)
                            GROUP BY detail_name
                            ORDER BY value DESC
                            LIMIT 5
                        ) t;

                        SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)
                        INTO json_expenses
                        FROM (
                            SELECT 
                                detail_name as name, 
                                ROUND(SUM(amount)::numeric, 2) as value
                            FROM mv_unified_transactions
                            WHERE user_id = p_user_id
                            AND type_transaction = 'expense'
                            AND (p_year IS NULL OR EXTRACT(YEAR FROM date_operation) = p_year)
                            AND (p_month IS NULL OR EXTRACT(MONTH FROM date_operation) = p_month)
                            GROUP BY detail_name
                            ORDER BY value DESC
                            LIMIT 5
                        ) t;

                        RETURN json_build_object(
                            'top_five_incomes', json_incomes,
                            'top_five_expenses', json_expenses
                        );
                    END;
                    $$ LANGUAGE plpgsql;
        SQL;
        DB::unprepared($sql);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
