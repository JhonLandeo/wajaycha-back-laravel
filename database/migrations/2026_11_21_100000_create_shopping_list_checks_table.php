<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user check-off of the weekly shopping list (ADR-0048 D5).
     *
     * A row means "the user already put this product in the cart". Unchecking
     * deletes the row: the absence is the false state, so there is no
     * `is_checked` column that could disagree with itself. The mark is scoped
     * to the week and independent of whether the product came from the menu or
     * from a habit.
     */
    public function up(): void
    {
        Schema::create('shopping_list_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueno de la marca. Limite de autorizacion, no un filtro de conveniencia (design.md D8).');

            $table->date('week_start')
                ->comment('Lunes de la semana de la lista; la marca no se comparte entre semanas.');

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('Producto marcado. RESTRICT: un producto referenciado por una marca no puede borrarse.');

            $table->timestampsTz();

            $table->unique(
                ['user_id', 'week_start', 'product_id'],
                'unq_shopping_list_checks_user_week_product'
            );
            $table->index(['user_id', 'week_start'], 'idx_shopping_list_checks_user_week');

            $table->comment('Check-off semanal de la compra por usuario y producto (ADR-0048 D5).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_checks');
    }
};
