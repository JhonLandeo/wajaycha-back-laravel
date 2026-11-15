<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cocina de la receta (ADR-0028). El catálogo y las propuestas nacen
     * peruanos; una receta puede marcarse internacional.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->text('cuisine')
                ->default('peruvian')
                ->comment('peruvian | international. Cocina de la receta.');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('cuisine');
        });
    }
};
