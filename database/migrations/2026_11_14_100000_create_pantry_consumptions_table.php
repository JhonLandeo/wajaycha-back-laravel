<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Libro append-only de consumo de despensa (ADR-0020).
     *
     * `pantry_items` sigue siendo el registro de lo que entró y nunca se edita;
     * el stock disponible se deriva restando estas filas. Una comida marcada
     * `cooked` escribe una fila por lote consumido, en orden FIFO por
     * vencimiento. Desmarcarla las borra y restaura el disponible.
     *
     * Las dos FKs son compuestas `(id, user_id)` para que un consumo no pueda
     * apuntar a un item de otro usuario aunque el id exista. Eliminar el item o
     * la entrada arrastra sus consumos: si el origen desaparece, el disponible
     * derivado no puede quedar negativo.
     */
    public function up(): void
    {
        Schema::table('pantry_items', function (Blueprint $table) {
            $table->unique(['id', 'user_id'], 'unq_pantry_items_id_user_id');
        });

        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->unique(['id', 'user_id'], 'unq_meal_plan_entries_id_user_id');
        });

        Schema::create('pantry_consumptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueño del consumo. Límite de autorización.');

            $table->unsignedBigInteger('pantry_item_id')
                ->comment('Lote de despensa consumido. Nunca se edita el item; se agrega esta fila.');

            $table->unsignedBigInteger('meal_plan_entry_id')
                ->comment('Comida que consumió el lote. Borrarla borra su consumo.');

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('Producto consumido, denormalizado para consultar por producto sin unir con pantry_items.');

            $table->decimal('quantity', 12, 3)
                ->comment('Cantidad consumida, en la unidad canónica del producto.');

            $table->text('unit')
                ->comment('Unidad del consumo; debe coincidir con pantry_items.unit y con Product::unit.');

            $table->date('consumed_on')
                ->default(DB::raw('CURRENT_DATE'))
                ->comment('Fecha en que se cocinó la comida (plan_date), en America/Lima.');

            $table->text('reason')
                ->comment('cooked | adjustment.');

            $table->timestampsTz();

            $table->unique(
                ['meal_plan_entry_id', 'pantry_item_id'],
                'unq_pantry_consumptions_entry_item'
            );

            $table->index(['user_id', 'product_id'], 'idx_pantry_consumptions_user_product');

            $table->foreign(['pantry_item_id', 'user_id'], 'fk_pantry_consumptions_pantry_item_id')
                ->references(['id', 'user_id'])
                ->on('pantry_items')
                ->cascadeOnDelete();

            $table->foreign(['meal_plan_entry_id', 'user_id'], 'fk_pantry_consumptions_meal_plan_entry_id')
                ->references(['id', 'user_id'])
                ->on('meal_plan_entries')
                ->cascadeOnDelete();

            $table->comment('Consumo de despensa por comida cocinada; el disponible es adquisiciones menos consumos (ADR-0020).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pantry_consumptions');

        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->dropUnique('unq_meal_plan_entries_id_user_id');
        });

        Schema::table('pantry_items', function (Blueprint $table) {
            $table->dropUnique('unq_pantry_items_id_user_id');
        });
    }
};
