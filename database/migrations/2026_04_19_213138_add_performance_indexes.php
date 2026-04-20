<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'date_operation'], 'idx_transactions_user_date');
            $table->index(['category_id'], 'idx_transactions_category');
        });

        Schema::table('details', function (Blueprint $table) {
            $table->index(['user_id', 'entity_clean'], 'idx_details_entity_clean');
        });

        Schema::table('categorization_rules', function (Blueprint $table) {
            $table->index(['user_id', 'detail_id'], 'idx_categorization_rules_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_user_date');
            $table->dropIndex('idx_transactions_category');
        });

        Schema::table('details', function (Blueprint $table) {
            $table->dropIndex('idx_details_entity_clean');
        });

        Schema::table('categorization_rules', function (Blueprint $table) {
            $table->dropIndex('idx_categorization_rules_lookup');
        });
    }
};
