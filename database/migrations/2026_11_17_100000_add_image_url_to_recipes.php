<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto de la receta para el menú y el recetario.
     *
     * Es una URL externa y opcional: las recetas del usuario y las generadas
     * por IA no necesitan una, y el cliente cae a una ilustración local cuando
     * no hay imagen o la URL deja de responder.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->text('image_url')
                ->nullable()
                ->after('instructions')
                ->comment('URL pública de una foto de la receta. NULL usa la ilustración local del cliente.');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
