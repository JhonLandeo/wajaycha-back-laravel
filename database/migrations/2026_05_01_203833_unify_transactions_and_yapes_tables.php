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
        // 1. Add future-proof columns to transactions
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('financial_entity_id')->nullable()->after('user_id');
            $table->foreign('financial_entity_id')->references('id')->on('financial_entities')->onDelete('set null');
            $table->unsignedBigInteger('payment_service_id')->nullable()->after('financial_entity_id');
            $table->foreign('payment_service_id')->references('id')->on('payment_services')->onDelete('set null');
            $table->string('source_type')->default('manual')->after('payment_service_id');
            $table->unsignedBigInteger('matched_transaction_id')->nullable()->after('source_type');
            $table->foreign('matched_transaction_id')->references('id')->on('transactions')->onDelete('set null');
            $table->unsignedBigInteger('old_yape_id')->nullable()->after('matched_transaction_id');
        });

        // 2. Set defaults for existing bank transactions
        DB::table('transactions')
            ->whereNull('source_type')
            ->orWhere('source_type', 'manual')
            ->orWhere('source_type', 'transaction')
            ->update([
                'source_type' => 'import_statement',
                'financial_entity_id' => 1, // BCP
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['financial_entity_id']);
            $table->dropForeign(['payment_service_id']);
            $table->dropForeign(['matched_transaction_id']);
            $table->dropColumn(['financial_entity_id', 'payment_service_id', 'source_type', 'matched_transaction_id', 'old_yape_id']);
        });
    }
};
