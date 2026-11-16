<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La despensa es opt-in al proponer recetas nuevas con IA (ADR-0039).
     *
     * Por defecto está apagada: la idea y la receta tradicional mandan. El
     * usuario la enciende cuando quiere que la IA aproveche lo que tiene.
     */
    public function up(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->boolean('consider_pantry_in_ai_recipes')
                ->default(false)
                ->comment('Si es true, la generación de recetas IA prioriza la despensa. Por defecto false.');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->dropColumn('consider_pantry_in_ai_recipes');
        });
    }
};
