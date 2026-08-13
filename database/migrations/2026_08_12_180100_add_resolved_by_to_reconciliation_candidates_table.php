<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who settled a suspected duplicate.
 *
 * The system now reconciles the unambiguous pairs itself instead of queueing
 * them, which is only defensible if what it decided stays visible and
 * reversible. That requires telling a pair the user judged from a pair the
 * system judged — otherwise the "we merged these, was any of it wrong?" list
 * would also offer to undo the answers the user gave on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_candidates', function (Blueprint $table) {
            $table->string('resolved_by', 10)
                ->nullable()
                ->after('status')
                ->comment('system | user. Null mientras sigue pendiente.');
        });

        // Las filas existentes resueltas, si las hubiera, son todas del usuario:
        // este es el primer cambio que deja al sistema decidir.
        DB::table('reconciliation_candidates')
            ->where('status', '!=', 'pending')
            ->whereNull('resolved_by')
            ->update(['resolved_by' => 'user']);

        DB::statement("
            ALTER TABLE reconciliation_candidates
            ADD CONSTRAINT chk_reconciliation_candidates_resolved_by
            CHECK (
                (status = 'pending') = (resolved_by IS NULL)
                AND (resolved_by IS NULL OR resolved_by IN ('system', 'user'))
            )
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reconciliation_candidates DROP CONSTRAINT IF EXISTS chk_reconciliation_candidates_resolved_by');

        Schema::table('reconciliation_candidates', function (Blueprint $table) {
            $table->dropColumn('resolved_by');
        });
    }
};
