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
        Schema::table('details', function (Blueprint $table) {
            $table->string('operation_type', 50)->nullable()->index();
            $table->text('entity_clean')->nullable();
            $table->timestamp('ai_reviewed_at')->nullable();
            $table->string('ai_verdict', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('details', function (Blueprint $table) {
            $table->dropColumn(['operation_type', 'entity_clean', 'ai_reviewed_at', 'ai_verdict']);
        });
    }
};
