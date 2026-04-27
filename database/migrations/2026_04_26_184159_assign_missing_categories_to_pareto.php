<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Find categories that are not assigned in category_pareto_assignments
        // and assign them to the 'Variables' classification of their user.
        // If 'Variables' is not found, fallback to any available classification for that user.
        
        DB::statement("
            INSERT INTO category_pareto_assignments (category_id, pareto_classification_id, created_at, updated_at)
            SELECT 
                c.id as category_id,
                COALESCE(
                    (SELECT pc.id FROM pareto_classifications pc WHERE pc.user_id = c.user_id AND pc.name = 'Variables' LIMIT 1),
                    (SELECT pc.id FROM pareto_classifications pc WHERE pc.user_id = c.user_id LIMIT 1)
                ) as pareto_classification_id,
                NOW(),
                NOW()
            FROM categories c
            WHERE NOT EXISTS (
                SELECT 1 FROM category_pareto_assignments cpa WHERE cpa.category_id = c.id
            )
            AND EXISTS (
                SELECT 1 FROM pareto_classifications pc WHERE pc.user_id = c.user_id
            );
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No easy way to distinguish these assignments from ones that were created intentionally.
        // Usually, fixing missing data doesn't require a rollback unless it's destructive.
    }
};
