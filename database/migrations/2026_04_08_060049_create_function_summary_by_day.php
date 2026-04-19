<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<SQL
            CREATE OR REPLACE FUNCTION get_summary_transaction_by_day(p_user_id INT)
            RETURNS TABLE (
                new_expense_day NUMERIC,
                income_total_by_month NUMERIC,
                avg_expense_day NUMERIC,
                total_expense NUMERIC,
                balance NUMERIC
            ) AS $$
            DECLARE
                v_amount_total NUMERIC := 2000;
                v_total_days NUMERIC;
                v_current_day NUMERIC;
            BEGIN
                -- Calculamos los días totales del mes actual
                v_total_days := EXTRACT(DAY FROM (date_trunc('month', CURRENT_DATE) + interval '1 month - 1 day'));
                -- Calculamos el día actual
                v_current_day := EXTRACT(DAY FROM CURRENT_DATE);

                RETURN QUERY
                WITH summary AS (
                    SELECT 
                        v_amount_total AS income_total_by_month,
                        ROUND((v_amount_total / v_total_days), 2) AS avg_expense_day,
                        COALESCE(SUM(amount), 0) AS total_expense,
                        ROUND(COALESCE(SUM(amount), 0) - (v_current_day * (v_amount_total / v_total_days)), 2) AS balance
                    FROM v_unified_transactions 
                    WHERE EXTRACT(MONTH FROM date_operation) = EXTRACT(MONTH FROM CURRENT_DATE)
                    AND EXTRACT(YEAR FROM date_operation) = EXTRACT(YEAR FROM CURRENT_DATE)
                    AND type_transaction = 'expense'
                    AND user_id = p_user_id
                )
                SELECT 
                    ROUND((s.income_total_by_month - s.balance) / v_total_days, 2) AS new_expense_day,
                    s.income_total_by_month,
                    s.avg_expense_day,
                    s.total_expense,
                    s.balance
                FROM summary s;
            END;
            $$ LANGUAGE plpgsql;
        SQL;
        DB::unprepared($sql);
    }

    public function down(): void
    {
        DB::unprepared("DROP FUNCTION IF EXISTS get_summary_transaction_by_day();");
    }
};
