<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Criterio de la sugerencia semanal (ADR-0020, decisión 5).
     *
     * `use_pantry` prioriza cubrir lo que ya hay; `save_money` prioriza lo que
     * falta comprar; `balanced` deja el comportamiento anterior con la
     * cobertura como desempate. No cambia la compra, solo el orden en que la
     * sugerencia elige recetas.
     */
    public function up(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->text('menu_strategy')
                ->default('balanced')
                ->comment('balanced | use_pantry | save_money. Criterio de la sugerencia de menú.');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_settings', function (Blueprint $table) {
            $table->dropColumn('menu_strategy');
        });
    }
};
