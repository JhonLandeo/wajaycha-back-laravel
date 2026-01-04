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
                    CREATE OR REPLACE FUNCTION get_monthly_data(
                        p_user_id INT,
                        p_is_checked BOOLEAN,
                        p_year INT DEFAULT NULL
                    )
                    RETURNS JSON AS $$
                    DECLARE
                        json_result JSON;

                        v_month_names CONSTANT text[] := ARRAY[
                            'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 
                            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
                        ];
                    BEGIN
                        SELECT json_agg(
                            CASE 
                                WHEN p_is_checked THEN json_build_object(
                                    'count_monthly_income', counts_income,
                                    'count_monthly_expense', counts_expense,
                                    'month', month_num,
                                    'name_month', v_month_names[month_num]
                                )
                                ELSE json_build_object(
                                    'sum_monthly_income', sum_income,
                                    'sum_monthly_expense', sum_expense,
                                    'month', month_num,
                                    'name_month', v_month_names[month_num]
                                )
                            END
                        ) INTO json_result
                        FROM (
                            SELECT
                                EXTRACT(MONTH FROM t.date_operation)::int as month_num,
                                
                                COUNT(CASE WHEN type_transaction = 'income' THEN 1 END) as counts_income,
                                COUNT(CASE WHEN type_transaction = 'expense' THEN 1 END) as counts_expense,
                                
                                ROUND(SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END)::numeric, 2) as sum_income,
                                ROUND(SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END)::numeric, 2) as sum_expense
                                
                            FROM mv_unified_transactions t
                            INNER JOIN details d ON t.detail_id = d.id
                            WHERE t.user_id = p_user_id
                            AND (p_year IS NULL OR EXTRACT(YEAR FROM t.date_operation) = p_year)
                            GROUP BY 1
                            ORDER BY 1 ASC
                        ) sub;

                        RETURN COALESCE(json_result, '[]'::json);
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
