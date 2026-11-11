<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El presupuesto de comida es un monto mensual explícito con un alcance de
     * categorías. No se deriva del presupuesto de una categoría: el usuario
     * puede dividir "Comida" en Mercado, Verdulería y Carnicería sin que el
     * total se duplique, y puede renombrar o crear hijas sin tocar el monto.
     *
     * Las categorías se guardan por id y con FK compuesta al dueño. El alcance
     * incluye a las descendientes de cada categoría elegida; esa expansión se
     * resuelve al leer, no se materializa acá.
     */
    public function up(): void
    {
        Schema::create('food_budgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueño del presupuesto. Límite de autorización, no un filtro de conveniencia.');

            $table->decimal('monthly_amount', 12, 2)
                ->default(0)
                ->comment('Monto mensual explícito para comida. No se deriva de las categorías.');

            $table->unsignedTinyInteger('shopping_day')
                ->default(7)
                ->comment('Día de compra ISO-8601: 1=lunes ... 7=domingo. Se usa para repartir el disponible.');

            $table->timestampsTz();

            $table->unique('user_id', 'unq_food_budgets_user_id');
            $table->comment('Presupuesto mensual de comida por usuario, con alcance de categorías.');
        });

        Schema::create('food_budget_categories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('food_budget_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Presupuesto de comida al que pertenece el alcance.');

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueño del presupuesto. Mitad de la FK compuesta a categories.');

            $table->unsignedBigInteger('category_id')
                ->comment('Categoría incluida en el alcance. Mitad de la FK compuesta a categories.');

            $table->timestampsTz();

            $table->unique(['food_budget_id', 'category_id'], 'unq_food_budget_categories_budget_category');
            $table->index(['category_id', 'user_id'], 'idx_food_budget_categories_category_user');
            $table->comment('Categorías que delimitan el gasto real del presupuesto de comida.');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE food_budget_categories
              ADD CONSTRAINT fk_food_budget_categories_category_id
              FOREIGN KEY (category_id, user_id) REFERENCES categories (id, user_id)
              ON DELETE CASCADE
        SQL);

        $now = now();

        $links = DB::table('grocery_budget_links as l')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->select('l.user_id', 'l.category_id', 'c.monthly_budget')
            ->get();

        foreach ($links as $link) {
            $foodBudgetId = DB::table('food_budgets')->insertGetId([
                'user_id' => $link->user_id,
                'monthly_amount' => $link->monthly_budget,
                'shopping_day' => 7,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('food_budget_categories')->insert([
                'food_budget_id' => $foodBudgetId,
                'user_id' => $link->user_id,
                'category_id' => $link->category_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('food_budget_categories');
        Schema::dropIfExists('food_budgets');
    }
};
