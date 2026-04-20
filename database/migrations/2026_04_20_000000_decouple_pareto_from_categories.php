<?php

declare(strict_types=1);

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
        // 1. Create assignments table
        Schema::create('category_pareto_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained()->onDelete('cascade');
            $table->foreignId('pareto_classification_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            $table->comment('Maintains the link between categories and pareto classifications for decoupling.');
        });

        // 2. Migrate existing data
        DB::statement('
            INSERT INTO category_pareto_assignments (category_id, pareto_classification_id, created_at, updated_at)
            SELECT id, pareto_classification_id, NOW(), NOW()
            FROM categories
            WHERE pareto_classification_id IS NOT NULL
        ');

        // 3. Drop column from categories
        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['pareto_classification_id']);
            $table->dropColumn('pareto_classification_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('pareto_classification_id')->nullable()->constrained();
        });

        DB::statement('
            UPDATE categories c
            SET pareto_classification_id = cpa.pareto_classification_id
            FROM category_pareto_assignments cpa
            WHERE c.id = cpa.category_id
        ');

        Schema::dropIfExists('category_pareto_assignments');
    }
};
