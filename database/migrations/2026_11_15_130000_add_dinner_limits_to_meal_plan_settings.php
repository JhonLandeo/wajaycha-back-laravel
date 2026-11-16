<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La cena tiene su propio tiempo y, opcionalmente, su propio tope de
     * calorías (ADR-0036). El tiempo máximo general sigue aplicando a las tres
     * comidas; este es un límite adicional solo para dinner.
     */
    public function up(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('dinner_max_prep_minutes')
                ->nullable()
                ->default(20)
                ->comment('Tiempo máximo de preparación solo para la cena. NULL = sin límite extra; el límite general sigue aplicando.');

            $table->unsignedSmallInteger('dinner_calorie_limit')
                ->nullable()
                ->comment('Tope aproximado de calorías por persona en la cena. NULL = sin tope extra.');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->dropColumn(['dinner_max_prep_minutes', 'dinner_calorie_limit']);
        });
    }
};
