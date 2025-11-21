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
                    WITH cleaned AS (
                        SELECT 
                            id,
                            description,
                            regexp_replace(
                                regexp_replace(
                                    unaccent(lower(description)),
                                    '[^a-z0-9\s]', '',
                                    'g'
                                ),
                                '\s+', ' ', 'g'
                            ) AS desc_clean
                        FROM details
                    ),
                    detail_similarity AS (
                        SELECT 
                            c1.id AS id_main,
                            c1.description AS description_main,
                            c2.id AS id_secondary,
                            c2.description AS description_secondary,
                            similarity(c1.desc_clean, c2.desc_clean) AS sim
                        FROM cleaned c1
                        JOIN cleaned c2 ON c1.id < c2.id
                        WHERE similarity(c1.desc_clean, c2.desc_clean) >= 0.5
                        AND c1.id NOT IN (365, 516, 310, 707, 107, 489)
                        and not ((c1.id = 15 and c2.id = 316) or (c1.id = 15 and c2.id = 412))
                        and not ((c1.id = 316 and c2.id = 365) or (c1.id = 316 and c2.id = 412))
                        and not ((c1.id = 175 and c2.id = 364))
                    ),
                    select_detail AS (
                        SELECT *
                        FROM detail_similarity ds
                        WHERE ds.id_main NOT IN (SELECT id_secondary FROM detail_similarity)
                    ),
                    update_transactions AS (
                        UPDATE transaction_yapes ty
                        SET detail_id = sd.id_main,
                            updated_at = now()
                        FROM select_detail sd
                        WHERE ty.detail_id = sd.id_secondary
                        RETURNING ty.*
                    ),
                    delete_rules AS (
                        DELETE FROM categorization_rules cr
                        WHERE cr.detail_id IN (SELECT id_secondary FROM select_detail)
                        RETURNING cr.*
                    )
                    DELETE FROM details d
                    WHERE d.id IN (SELECT id_secondary FROM select_detail);
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
