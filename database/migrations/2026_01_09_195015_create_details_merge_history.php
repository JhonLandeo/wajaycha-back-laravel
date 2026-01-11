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
        Schema::create('details_merge_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_detail_id');
            $table->unsignedBigInteger('target_detail_id');
            $table->jsonb('original_data');
            $table->text('merge_reason')->nullable();
            $table->jsonb('moved_transaction_ids')->default('[]');
            $table->jsonb('moved_yape_ids')->default('[]');
            $table->timestamp('merged_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('details_merge_history');
    }
};
