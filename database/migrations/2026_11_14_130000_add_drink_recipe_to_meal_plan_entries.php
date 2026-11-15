<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una bebida para la misma comida (ADR-0021).
     *
     * El plato principal sigue en `recipe_id` y debe ser comida; la bebida va
     * acá y solo acepta recetas `course = drink`. Así no se pueden elegir dos
     * bebidas ni una bebida como plato único del desayuno.
     */
    public function up(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->foreignId('drink_recipe_id')
                ->nullable()
                ->constrained('recipes')
                ->nullOnDelete()
                ->comment('Bebida de la comida. Solo recetas course=drink. NULL cuando no lleva.');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('drink_recipe_id');
        });
    }
};
