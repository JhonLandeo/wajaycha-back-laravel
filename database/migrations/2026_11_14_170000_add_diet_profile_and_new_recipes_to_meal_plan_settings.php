<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perfil de alimentación y cuántas recetas nuevas puede proponer la IA por
     * semana (ADR-0024). `new_recipes_per_week = 0` significa solo recetario.
     */
    public function up(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->text('diet_profile')
                ->default('balanced')
                ->comment('balanced | vegetarian | vegan | high_protein | light. Perfil de alimentación.');

            $table->unsignedTinyInteger('new_recipes_per_week')
                ->default(5)
                ->comment('Máximo de recetas nuevas que la IA puede crear por semana. 0 = solo recetario.');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->dropColumn(['diet_profile', 'new_recipes_per_week']);
        });
    }
};
