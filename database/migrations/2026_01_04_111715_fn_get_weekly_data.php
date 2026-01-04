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
                    CREATE OR REPLACE FUNCTION get_weekly_data(
                        p_user_id INT,
                        p_is_checked BOOLEAN,
                        p_year INT DEFAULT NULL,
                        p_month INT DEFAULT NULL
                    )
                    RETURNS JSON AS $$
                    DECLARE
                        json_result JSON;
                    BEGIN
                        SELECT json_agg(
                            CASE 
                                WHEN p_is_checked THEN json_build_object(
                                    'count_weekly_income', counts_income,
                                    'count_weekly_expense', counts_expense,
                                    'day', day_num,
                                    'name_day', day_name
                                )
                                ELSE json_build_object(
                                    'sum_weekly_income', sum_income,
                                    'sum_weekly_expense', sum_expense,
                                    'day', day_num,
                                    'name_day', day_name
                                )
                            END
                        ) INTO json_result
                        FROM (
                            SELECT
                                EXTRACT(ISODOW FROM t.date_operation) as day_num,
                                -- INITCAP pone la primera letra en mayúscula (igual que mb_convert_case Title)
                                INITCAP(TRIM(to_char(t.date_operation, 'Day'))) as day_name,
                                
                                -- Calculamos conteos
                                COUNT(CASE WHEN type_transaction = 'income' THEN 1 END) as counts_income,
                                COUNT(CASE WHEN type_transaction = 'expense' THEN 1 END) as counts_expense,
                                
                                -- Calculamos sumas (coalesce para evitar nulls)
                                ROUND(SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END)::numeric, 2) as sum_income,
                                ROUND(SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END)::numeric, 2) as sum_expense
                                
                            FROM mv_unified_transactions t
                            INNER JOIN details d ON t.detail_id = d.id
                            WHERE t.user_id = p_user_id
                            AND (p_year IS NULL OR EXTRACT(YEAR FROM t.date_operation) = p_year)
                            AND (p_month IS NULL OR EXTRACT(MONTH FROM t.date_operation) = p_month)
                            GROUP BY 1, 2
                            ORDER BY 1 ASC
                        ) sub;

                        -- Devolver array vacío si no hay resultados en lugar de NULL
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
