<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suspected duplicates awaiting a human decision.
 *
 * `matched_transaction_id` already records a settled reconciliation, and the
 * reporting functions honour it — `fn_get_transactions` counts only rows whose
 * `matched_transaction_id` is null. That column cannot carry a suspicion: writing
 * it would make the movement disappear from the totals on the system's guess
 * alone, and merging two real expenses into one is the failure a user cannot see.
 *
 * So a suspicion lives here instead, and both rows keep being counted until
 * someone confirms. An inflated total that is flagged in the interface is honest.
 * A missing one is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_candidates', function (Blueprint $table) {
            $table->comment('Pares de transacciones que parecen el mismo movimiento real y esperan la decision del usuario.');

            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('transaction_id')
                ->constrained('transactions')
                ->cascadeOnDelete()
                ->comment('La fila recien creada que disparo la sospecha.');

            $table->foreignId('candidate_transaction_id')
                ->constrained('transactions')
                ->cascadeOnDelete()
                ->comment('La fila preexistente que podria ser el mismo movimiento.');

            $table->string('status', 20)
                ->default('pending')
                ->comment('pending | confirmed | rejected. Ver App\Enums\ReconciliationStatus.');

            $table->timestampTz('resolved_at')
                ->nullable()
                ->comment('Cuando el usuario decidio. Null mientras sigue pendiente.');

            $table->timestampsTz();

            $table->index(['user_id', 'status'], 'idx_reconciliation_candidates_user_status');
        });

        // Un par es un par en cualquier orden. Sin normalizar, (7,9) y (9,7) son dos
        // filas distintas y el mismo par se propone dos veces.
        DB::statement('
            CREATE UNIQUE INDEX unq_reconciliation_candidates_pair
            ON reconciliation_candidates (
                LEAST(transaction_id, candidate_transaction_id),
                GREATEST(transaction_id, candidate_transaction_id)
            )
        ');

        // El indice de arriba es tambien lo que hace permanente un descarte: la fila
        // rechazada se queda, y el par no se puede volver a insertar. Sin eso cada
        // reimportacion vuelve a preguntar lo que el usuario ya respondio.

        DB::statement("
            ALTER TABLE reconciliation_candidates
            ADD CONSTRAINT chk_reconciliation_candidates_distinct
            CHECK (transaction_id <> candidate_transaction_id)
        ");

        DB::statement("
            ALTER TABLE reconciliation_candidates
            ADD CONSTRAINT chk_reconciliation_candidates_status
            CHECK (status IN ('pending', 'confirmed', 'rejected'))
        ");

        // Una decision tiene fecha y una pendiente no. Es dinero: si el estado y la
        // marca de tiempo se separan, no queda forma de auditar cuando se resolvio.
        DB::statement("
            ALTER TABLE reconciliation_candidates
            ADD CONSTRAINT chk_reconciliation_candidates_resolved_at
            CHECK ((status = 'pending') = (resolved_at IS NULL))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_candidates');
    }
};
