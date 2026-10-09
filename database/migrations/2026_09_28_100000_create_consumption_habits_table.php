<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user declared weekly need (design.md Slice 3, spec.md "Declared
     * consumption habit"). Reuses slice 2's shape: `restrictOnDelete()` on
     * `product_id` for the same reason `pantry_items` does (a seeded global
     * product has `user_id IS NULL`, incompatible with a composite FK under
     * PostgreSQL's MATCH SIMPLE — design.md D3's accepted weakness).
     *
     * `unq_consumption_habits_user_id_product_id` is the arbiter for "declaring
     * the same product twice updates, not duplicates" (tasks.md 3.3) — the
     * repository upserts against this index rather than checking first.
     */
    public function up(): void
    {
        Schema::create('consumption_habits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueno del habito. Limite de autorizacion, no un filtro de conveniencia (design.md D8).');

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('Producto sobre el que se declara la necesidad semanal. RESTRICT: un producto referenciado por un habito no puede borrarse.');

            $table->decimal('weekly_quantity', 12, 3)
                ->comment('Cantidad semanal declarada, en la unidad canonica del producto.');

            $table->text('unit')
                ->comment('Debe coincidir exactamente con Product::unit; un desajuste es un error de validacion, nunca una conversion silenciosa.');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Permite pausar un habito sin perder que fue declarado.');

            $table->timestampsTz();

            $table->unique(['user_id', 'product_id'], 'unq_consumption_habits_user_id_product_id');
            $table->index(['user_id', 'is_active'], 'idx_consumption_habits_user_active');

            $table->comment('Necesidad semanal declarada por usuario y producto (spec.md "Declared consumption habit").');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumption_habits');
    }
};
