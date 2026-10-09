<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared grocery catalogue: global by default, with a private
     * per-user extension (design.md D3). `user_id` is nullable — NULL means
     * the seeded catalogue every user reads; a value means one user's own
     * addition, invisible to everyone else.
     *
     * No column anticipates phase 2 (seasonality) or phase 3 (price): both
     * land as purely additive migrations later (design.md, "No column
     * anticipates phase 2 or 3").
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete()
                ->comment('NULL para el catalogo global sembrado; con valor, la adicion privada de ese usuario (design.md D3).');

            $table->text('slug')
                ->nullable()
                ->comment('Identificador estable solo para filas sembradas. NULL en las adiciones de usuario: es lo que las mantiene fuera de unq_products_slug.');

            $table->text('name')
                ->comment('Nombre canonico en espanol, por ejemplo "Papa blanca".');

            $table->text('unit')
                ->comment('Valor de App\\Enums\\Unit. Sin CHECK: se valida en el FormRequest.');

            $table->boolean('is_active')
                ->default(true)
                ->comment('Retira un producto del catalogo sin borrar una fila que un pantry item referencia.');

            $table->timestampsTz();

            // PostgreSQL trata cada NULL como distinto en un indice unico, asi
            // que esta restriccion no limita cuantas adiciones privadas
            // existan (slug es NULL en todas ellas) — solo impide dos filas
            // globales con el mismo slug.
            $table->unique('slug', 'unq_products_slug');

            // Complemento del guard anterior: un usuario no puede declarar el
            // mismo producto dos veces. Los user_id NULL tambien son
            // distintos entre si, asi que esto no limita el catalogo global
            // — slug ya lo cubre.
            $table->unique(['user_id', 'name'], 'unq_products_user_id_name');

            $table->comment('Catalogo de productos de abarrotes: global sembrado mas la extension privada de cada usuario (design.md D3).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
