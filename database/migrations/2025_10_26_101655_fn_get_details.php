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
                CREATE OR REPLACE FUNCTION get_details(p_per_page integer, p_page integer)
                RETURNS TABLE(id bigint, name character varying, created_at timestamp without time zone, category_name character varying, total_count bigint)
                LANGUAGE plpgsql
                AS $$
                    DECLARE
                    offset_val int;
                    BEGIN
                    offset_val = (p_page - 1) * p_per_page;	

                    RETURN QUERY
                    WITH detail_primary_category AS (
                        SELECT
                            ranked_categories.detail_id,
                            ranked_categories.category_name
                        FROM (
                            SELECT
                                t.detail_id,
                                c.name AS category_name,
                                ROW_NUMBER() OVER(PARTITION BY t.detail_id ORDER BY COUNT(*) DESC) as rn
                            FROM transactions t
                            JOIN categories c ON c.id = t.category_id
                            GROUP BY t.detail_id, c.name
                        ) ranked_categories
                        WHERE rn = 1
                    )
                    SELECT
                        d.id,
                        d.description,
                        d.created_at,
                        dpc.category_name,
                        tc.total_count
                    FROM details d
                    LEFT JOIN detail_primary_category dpc ON dpc.detail_id = d.id
                    CROSS JOIN (
                        SELECT COUNT(DISTINCT t.detail_id) AS total_count
                        FROM transactions t
                    ) AS tc
                    WHERE EXISTS (SELECT 1 FROM transactions t WHERE t.detail_id = d.id)
                    ORDER BY
                        CASE WHEN dpc.category_name IS NOT NULL THEN 0 ELSE 1 END,
                        d.id
                    LIMIT p_per_page
                    OFFSET offset_val;

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
        DB::unprepared('DROP FUNCTION IF EXISTS get_details(integer, integer);');
    }
};
