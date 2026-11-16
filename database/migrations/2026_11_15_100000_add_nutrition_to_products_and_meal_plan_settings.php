<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nutrición aproximada y objetivos declarados (ADR-0031).
     *
     * Los valores viven en el producto y no en la receta: una receta se compone
     * de productos, así que sus macros se calculan. La unidad de los valores es
     * la unidad canónica del producto (`kg`, `l`, `unidad`, ...), nunca una
     * unidad de receta como `g` sobre un producto en `kg`.
     *
     * El objetivo diario y el límite de carbohidratos de la cena son opcionales.
     * NULL significa "no lo evalúes"; nunca se deriva de peso, edad ni actividad.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('calories_kcal', 10, 2)
                ->nullable()
                ->comment('Calorías aproximadas por unidad canónica del producto. NULL = sin dato.');

            $table->decimal('protein_g', 10, 2)
                ->nullable()
                ->comment('Proteína aproximada en gramos por unidad canónica del producto.');

            $table->decimal('carbs_g', 10, 2)
                ->nullable()
                ->comment('Carbohidratos aproximados en gramos por unidad canónica del producto.');

            $table->decimal('fat_g', 10, 2)
                ->nullable()
                ->comment('Grasa aproximada en gramos por unidad canónica del producto.');
        });

        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('daily_calorie_target')
                ->nullable()
                ->comment('Objetivo de calorías por persona y día. NULL = la sugerencia no evalúa déficit.');

            $table->unsignedSmallInteger('dinner_carb_limit_g')
                ->nullable()
                ->comment('Máximo aproximado de carbohidratos por persona en la cena. NULL = sin límite.');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['calories_kcal', 'protein_g', 'carbs_g', 'fat_g']);
        });

        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->dropColumn(['daily_calorie_target', 'dinner_carb_limit_g']);
        });
    }
};
