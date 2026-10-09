<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user pantry recording (design.md Slice 2, spec.md "Pantry item
     * recording"). Expiry never deletes or archives a row — the query that
     * computes availability simply stops counting it (spec.md "Expired items
     * excluded from availability").
     *
     * `restrictOnDelete()` on `product_id`, not a composite FK: a seeded
     * global product has `user_id IS NULL`, which a composite
     * `(product_id, user_id) -> products(id, user_id)` foreign key cannot
     * match under PostgreSQL's MATCH SIMPLE (design.md D3's accepted
     * weakness — the same one `products.user_id` already lives with).
     * Visibility of the referenced product is a FormRequest concern
     * (StorePantryItemRequest), not a schema one.
     */
    public function up(): void
    {
        Schema::create('pantry_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueno del item. Limite de autorizacion, no un filtro de conveniencia (design.md D8).');

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('Producto al que corresponde este item. RESTRICT: un producto referenciado por un item de despensa no puede borrarse.');

            $table->decimal('quantity', 12, 3)
                ->comment('Cantidad disponible, en la unidad canonica del producto.');

            $table->text('unit')
                ->comment('Debe coincidir exactamente con Product::unit; un desajuste es un error de validacion, nunca una conversion silenciosa.');

            $table->text('acquisition_source')
                ->comment('Valor de App\\Enums\\AcquisitionSource (purchased|gift|harvested). Un "gift" resta de la lista pero nunca del presupuesto ni escribe una Transaction.');

            $table->date('acquired_on')
                ->default(DB::raw('CURRENT_DATE'))
                ->comment('Fecha en que se adquirio el item.');

            $table->date('expires_on')
                ->nullable()
                ->comment('Pasada esta fecha el item deja de contar como disponible; la fila se conserva siempre, nunca se borra ni se archiva automaticamente.');

            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'product_id'], 'idx_pantry_items_user_product');
            $table->index(['user_id', 'expires_on'], 'idx_pantry_items_user_expires');

            $table->comment('Registro de despensa por usuario: cantidad disponible, fuente de adquisicion y vencimiento opcional (spec.md "Pantry item recording").');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pantry_items');
    }
};
