<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->jsonb('excluded_terms')
                ->default(DB::raw("'[]'::jsonb"))
                ->comment('Productos o comidas que la sugerencia no debe proponer: alergias, dietas o disgustos.');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->dropColumn('excluded_terms');
        });
    }
};
