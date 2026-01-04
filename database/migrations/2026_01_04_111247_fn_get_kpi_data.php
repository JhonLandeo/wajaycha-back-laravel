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
                     CREATE OR REPLACE FUNCTION get_kpi_data(
                        p_user_id INT,
                        p_year INT DEFAULT NULL,
                        p_month INT DEFAULT NULL
                    )
                    RETURNS JSON AS $$
                    DECLARE
                        json_result JSON;
                    BEGIN
                        SELECT json_build_object(
                            'avg_daily_income', json_build_object(
                                'amount', ROUND(AVG(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END)::numeric, 2),
                                'title', 'AVG Ingreso diario',
                                'type', 'income'
                            ),
                            'avg_daily_expense', json_build_object(
                                'amount', ROUND(AVG(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END)::numeric, 2),
                                'title', 'AVG Gasto diario',
                                'type', 'expense'
                            ),
                            'total_income', json_build_object(
                                'amount', COALESCE(SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END), 0),
                                'title', 'Total de ingresos',
                                'type', 'income'
                            ),
                            'total_expense', json_build_object(
                                'amount', COALESCE(SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END), 0),
                                'title', 'Total de gastos',
                                'type', 'expense'
                            ),
                            'balance', json_build_object(
                                'amount', COALESCE(SUM(CASE WHEN type_transaction = 'income' THEN amount ELSE 0 END), 0) 
                                        - COALESCE(SUM(CASE WHEN type_transaction = 'expense' THEN amount ELSE 0 END), 0),
                                'title', 'Balance'
                            )
                        ) INTO json_result
                        FROM mv_unified_transactions t
                        INNER JOIN details d ON t.detail_id = d.id
                        WHERE t.user_id = p_user_id
                        AND (p_year IS NULL OR EXTRACT(YEAR FROM t.date_operation) = p_year)
                        AND (p_month IS NULL OR EXTRACT(MONTH FROM t.date_operation) = p_month);

                        IF json_result IS NULL THEN 
                            RETURN '{}'::json;
                        END IF;

                        RETURN json_result;
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
