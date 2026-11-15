<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Etiquetas de dieta por receta (ADR-0024): vegetarian, vegan,
     * high_protein, light.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->jsonb('diet_tags')
                ->default(DB::raw("'[]'::jsonb"))
                ->comment('Valores de App\\Enums\\DietTag. vegetarian/vegan filtran; high_protein/light priorizan.');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('diet_tags');
        });
    }
};
