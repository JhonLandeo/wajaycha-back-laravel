<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user pin of the resolved grocery Category (design.md Slice 4, M4,
 * spec.md "Lazy-resolve-then-pin lifecycle").
 *
 * `category_id` carries NO single-column FK on purpose — the composite FK
 * added below with raw SQL is the real constraint, because the Schema
 * builder cannot express `(category_id, user_id) -> categories (id, user_id)`.
 * `unq_categories_id_user_id` already exists
 * (2026_08_11_100000_enforce_cross_user_ownership.php:99), so the referenced
 * side is already addressable and no new unique is needed on `categories`.
 *
 * `ON DELETE CASCADE` is written EXPLICITLY — this is the bound decision
 * (design.md D9, M4). The precedent migration adds its own composite FKs
 * with no ON DELETE clause at all
 * (2026_08_11_100000_enforce_cross_user_ownership.php:111,117,128,134), so
 * PostgreSQL defaults to NO ACTION there. Copying that verbatim here would
 * make deleting a renamed grocery Category fail with a constraint error the
 * SPA cannot explain. `App\Models\Category` carries no `SoftDeletes` — the
 * delete is physical, and there is no soft-delete path to fall back on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grocery_budget_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete()
                ->comment('Dueno del pin. Limite de autorizacion, no un filtro de conveniencia (design.md D8).');

            $table->unsignedBigInteger('category_id')
                ->comment('Mitad de la FK compuesta agregada abajo con SQL crudo; no lleva FK de una sola columna (design.md D2, M4).');

            $table->text('resolved_by')
                ->comment('auto | manual — como se obtuvo el pin (design.md D6). Sin CHECK: la regla vive en el FormRequest/Action, siguiendo el precedente de categories.type.');

            $table->timestampTz('linked_at')
                ->comment('Momento en que se tomo el pin — no coincide necesariamente con created_at en un pin manual que reemplaza a uno automatico.');

            $table->timestampsTz();

            $table->unique('user_id', 'unq_grocery_budget_links_user_id');
            $table->index(['category_id', 'user_id'], 'idx_grocery_budget_links_category_user');

            $table->comment('Pin por usuario de la Category desde la que se lee el techo de compras del supermercado (spec.md "Lazy-resolve-then-pin lifecycle"). Una fila por usuario.');
        });

        // La FK compuesta: el Schema builder no puede expresar
        // (category_id, user_id) -> categories (id, user_id). El indice
        // idx_grocery_budget_links_category_user evita un scan completo de
        // esta tabla cuando se borra una categoria (mismo razonamiento que
        // idx_transactions_category_user en el precedente).
        DB::statement(<<<'SQL'
            ALTER TABLE grocery_budget_links
              ADD CONSTRAINT fk_grocery_budget_links_category_id
              FOREIGN KEY (category_id, user_id) REFERENCES categories (id, user_id)
              ON DELETE CASCADE
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('grocery_budget_links');
    }
};
