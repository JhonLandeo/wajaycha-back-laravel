<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('transaction_yapes')) {
            // 1. Reassign tags from duplicate Yapes to the 'original' record (MIN ID)
            DB::statement("
                UPDATE transaction_tag tt
                SET transaction_yape_id = sub.min_id
                FROM (
                    SELECT id, MIN(id) OVER (PARTITION BY amount, date_operation, user_id, type_transaction, message) as min_id
                    FROM transaction_yapes
                ) as sub
                WHERE tt.transaction_yape_id = sub.id
                AND tt.transaction_yape_id != sub.min_id
            ");

            // 2. Delete duplicate tags if they exist (pivot table deduplication)
            DB::statement("
                DELETE FROM transaction_tag a
                USING transaction_tag b
                WHERE a.ctid < b.ctid
                  AND a.tag_id = b.tag_id
                  AND a.transaction_yape_id IS NOT DISTINCT FROM b.transaction_yape_id
                  AND a.transaction_id IS NOT DISTINCT FROM b.transaction_id
            ");

            // 3. Now delete duplicate Yape records from the source table
            DB::statement("
                DELETE FROM transaction_yapes 
                WHERE id NOT IN (
                    SELECT MIN(id) 
                    FROM transaction_yapes 
                    GROUP BY amount, date_operation, user_id, type_transaction, message
                )
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }
};
