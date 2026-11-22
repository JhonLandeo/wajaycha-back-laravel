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
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->foreignId('rescheduled_from_entry_id')
                ->nullable()
                ->after('leftover_of_entry_id')
                ->comment('Entrada anterior que esta comida reprograma.');
            $table->foreign('rescheduled_from_entry_id')
                ->references('id')
                ->on('meal_plan_entries')
                ->nullOnDelete();
            $table->unique('rescheduled_from_entry_id', 'unq_meal_plan_entries_rescheduled_from_entry_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rescheduled_from_entry_id');
        });
    }
};
