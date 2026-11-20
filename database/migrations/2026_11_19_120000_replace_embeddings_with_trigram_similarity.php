<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La categorización deja de depender de embeddings.
 *
 * El paso vectorial de la cascada costaba una llamada a Gemini por comercio
 * desconocido, no tenía índice y vectorizaba `description` crudo. Lo reemplaza
 * `pg_trgm` sobre `entity_clean`, que ya se usa en Entity Resolution y no tiene
 * costo por movimiento.
 *
 * `last_used_category_id` sobrevive como la memoria de la categoría asignada a
 * cada comercio: antes solo lo escribía el job de embeddings, así que se rellena
 * desde `categorization_rules` para que la nueva cascada y la pestaña de
 * sugerencias tengan de dónde partir.
 */
return new class extends Migration
{
    private const TRIGRAM_INDEX = 'idx_details_entity_trgm';

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');

        // Las reglas ya son la decisión explícita del usuario; el vector solo era
        // otra forma de llegar a la misma categoría. Sin este relleno, todo lo
        // aprendido antes de este cambio quedaría invisible para la nueva cascada.
        DB::statement(<<<'SQL'
            UPDATE details AS d
            SET last_used_category_id = cr.category_id
            FROM categorization_rules AS cr
            WHERE cr.detail_id = d.id
              AND cr.user_id = d.user_id
              AND d.last_used_category_id IS NULL
        SQL);

        // `%` filtra por similitud de trigramas usando este índice; el orden final
        // sigue siendo `similarity()`, que el índice no puede resolver por sí solo.
        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON details USING gin ((coalesce(entity_clean, description)) gin_trgm_ops)',
            self::TRIGRAM_INDEX
        ));

        if (Schema::hasColumn('details', 'embedding')) {
            Schema::table('details', function (Blueprint $table): void {
                $table->dropColumn('embedding');
            });
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::TRIGRAM_INDEX);

        if (! Schema::hasColumn('details', 'embedding')) {
            Schema::table('details', function (Blueprint $table): void {
                $table->vector('embedding', 768)->nullable();
            });
        }
    }
};
