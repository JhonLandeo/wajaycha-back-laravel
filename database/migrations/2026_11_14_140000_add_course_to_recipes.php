<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qué papel cumple la receta: plato principal, bebida o acompañamiento
     * (ADR-0021). El sugeridor usa el tipo para armar un desayuno con bebida y
     * acompañamiento en vez de proponer solo la bebida.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->text('course')
                ->default('main')
                ->comment('main | drink | side. Papel de la receta dentro de una comida.');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('course');
        });
    }
};
