<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Origen de la receta (ADR-0023): catálogo sembrado, receta propia o
     * propuesta por la IA. Permite mostrar la marca "Propuesta IA" y contar
     * cuántas generó la IA en la semana.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->text('origin')
                ->default('user')
                ->comment('catalog | user | ai. Origen de la receta.');
        });

        DB::table('recipes')->whereNull('user_id')->update(['origin' => 'catalog']);
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
