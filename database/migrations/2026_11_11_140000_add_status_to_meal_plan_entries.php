<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->text('status')
                ->default('planned')
                ->comment('planned | cooked | leftover | ate_out | skipped. Estado real de la comida planificada.');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
