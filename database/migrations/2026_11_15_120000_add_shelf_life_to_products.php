<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vida útil aproximada desde la adquisición (ADR-0035).
     *
     * `expires_on` en la despensa sigue mandando: si el usuario cargó una fecha
     * concreta, esa fecha gana. Esta columna solo estima el vencimiento cuando
     * el lote no tiene fecha, para poder priorizar lo que se malogra primero.
     * NULL significa que el producto no tiene una vida útil corta conocida.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('shelf_life_days')
                ->nullable()
                ->comment('Vida útil aproximada en días desde acquired_on. NULL = no se estima vencimiento.');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('shelf_life_days');
        });
    }
};
