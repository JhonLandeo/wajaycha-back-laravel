<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equipamiento de cocina (ADR-0022).
     *
     * `recipes.required_equipment` declara lo que necesita una receta;
     * `meal_plan_settings.available_equipment` declara lo que el usuario tiene.
     * NULL en la configuración significa "sin restricción"; un arreglo vacío
     * significa "no tengo equipamiento".
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->jsonb('required_equipment')
                ->default(DB::raw("'[]'::jsonb"))
                ->comment('Valores de App\\Enums\\KitchenEquipment que la receta necesita (stove, oven, blender, pressure_cooker, microwave, no_cook).');
        });

        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->jsonb('available_equipment')
                ->nullable()
                ->comment('Equipamiento disponible. NULL = sin restricción; [] = ninguno.');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->dropColumn('available_equipment');
        });

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('required_equipment');
        });
    }
};
