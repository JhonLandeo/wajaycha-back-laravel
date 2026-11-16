<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado por usuario de una receta global (ADR-0030).
     *
     * El catálogo es compartido; esta fila permite que un usuario desactive una
     * receta del catálogo para su propia sugerencia sin afectar a los demás.
     */
    public function up(): void
    {
        Schema::create('recipe_user_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueño del estado. Límite de autorización.');

            $table->foreignId('recipe_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Receta global cuyo estado se personaliza.');

            $table->boolean('is_enabled')
                ->default(true)
                ->comment('Si el usuario quiere que la sugerencia considere esta receta global.');

            $table->timestampsTz();

            $table->unique(['user_id', 'recipe_id'], 'unq_recipe_user_states_user_recipe');
            $table->comment('Estado por usuario de las recetas del catálogo (ADR-0030).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_user_states');
    }
};
