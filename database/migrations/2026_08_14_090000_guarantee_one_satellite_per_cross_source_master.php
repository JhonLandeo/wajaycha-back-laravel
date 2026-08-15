<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un asiento del banco explica UN pago, y la base lo garantiza.
 *
 * El defecto que motiva esto: `matched_transaction_id` lo lleva el SATELITE, asi
 * que el MAESTRO de un par resuelto conserva el suyo en null y toda consulta que
 * filtre por `whereNull('matched_transaction_id')` lo sigue viendo libre. Con dos
 * cobros de S/ 5 el mismo dia, los dos movimientos de Yape se colgaron del mismo
 * asiento y el otro asiento quedo contando solo. El total dio bien de casualidad;
 * la pantalla mostro un cobro con dos origenes y otro con ninguno.
 *
 * Eso ya se filtra en la consulta, y filtrar en la consulta no es una garantia —
 * la misma distincion que `docs/` ya hace sobre el acceso read-only y que
 * `chk_transactions_source_type` hizo con el enum. Quedaban dos agujeros: dos
 * importaciones simultaneas pueden leer las dos que el asiento esta libre, y hay
 * tres lugares que escriben `matched_transaction_id` sin pasar por ese filtro.
 *
 * NO es un indice unico sobre `transactions.matched_transaction_id`, que seria lo
 * obvio y seria incorrecto: `ReconcileImportDuplicates` colapsa a proposito un
 * grupo de tres copias de la MISMA fuente sobre un solo sobreviviente, y ahi un
 * maestro con dos satelites es lo que hay que hacer. La regla de "uno y solo uno"
 * pertenece al cruce ENTRE fuentes distintas, donde los montos se comparan exactos
 * y un asiento del banco es un pago. De ahi que haga falta `kind`: sin el, la base
 * no puede distinguir las dos operaciones y no puede proteger ninguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_candidates', function (Blueprint $table) {
            $table->string('kind', 20)
                ->default('cross_source')
                ->comment('cross_source | same_source. Ver App\Enums\ReconciliationKind.');

            $table->foreignId('master_transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->cascadeOnDelete()
                ->comment('La fila que sigue contando. Null mientras el par no este confirmado.');
        });

        $this->backfillKind();
        $this->backfillMaster();
        $this->demoteMastersClaimedTwice();

        DB::statement("
            ALTER TABLE reconciliation_candidates
            ADD CONSTRAINT chk_reconciliation_candidates_kind
            CHECK (kind IN ('cross_source', 'same_source'))
        ");

        // El maestro existe exactamente cuando hay algo que contar, igual que
        // `resolved_at` existe exactamente cuando hay una decision. Un par pendiente
        // con maestro seria un movimiento descontado antes de que nadie lo decida.
        DB::statement("
            ALTER TABLE reconciliation_candidates
            ADD CONSTRAINT chk_reconciliation_candidates_master_when_confirmed
            CHECK ((status = 'confirmed') = (master_transaction_id IS NOT NULL))
        ");

        // El maestro tiene que ser uno de los dos lados del par. Sin esto la columna
        // acepta cualquier transaccion del sistema y el par deja de significar algo.
        DB::statement('
            ALTER TABLE reconciliation_candidates
            ADD CONSTRAINT chk_reconciliation_candidates_master_belongs
            CHECK (
                master_transaction_id IS NULL
                OR master_transaction_id IN (transaction_id, candidate_transaction_id)
            )
        ');

        // LA garantia. Parcial en las dos condiciones: solo los pares confirmados
        // descuentan algo, y solo los cruzados prometen uno a uno.
        DB::statement("
            CREATE UNIQUE INDEX unq_reconciliation_candidates_cross_source_master
            ON reconciliation_candidates (master_transaction_id)
            WHERE kind = 'cross_source' AND status = 'confirmed'
        ");
    }

    /**
     * Un par es cruzado cuando sus dos lados vienen de puertas distintas.
     *
     * Se lee de los datos y no se asume: los pares que ya existen los escribieron
     * dos caminos distintos — el detector, que solo cruza fuentes, y el comando de
     * limpieza, que solo junta iguales — y el default de la columna solo seria
     * correcto para uno de los dos.
     */
    private function backfillKind(): void
    {
        DB::statement("
            UPDATE reconciliation_candidates rc
            SET kind = CASE WHEN a.source_type IS DISTINCT FROM b.source_type
                            THEN 'cross_source' ELSE 'same_source' END
            FROM transactions a, transactions b
            WHERE a.id = rc.transaction_id AND b.id = rc.candidate_transaction_id
        ");
    }

    /**
     * El maestro de un par confirmado es el lado que NO quedo apuntando al otro.
     *
     * Se deduce de `matched_transaction_id`, que es donde la decision vive hoy. Un
     * par confirmado cuyos dos lados quedaron sueltos no tiene maestro deducible y
     * cae abajo, en la democion.
     */
    private function backfillMaster(): void
    {
        DB::statement("
            UPDATE reconciliation_candidates rc
            SET master_transaction_id = CASE
                    WHEN a.matched_transaction_id = b.id THEN b.id
                    WHEN b.matched_transaction_id = a.id THEN a.id
                END
            FROM transactions a, transactions b
            WHERE a.id = rc.transaction_id
              AND b.id = rc.candidate_transaction_id
              AND rc.status = 'confirmed'
        ");
    }

    /**
     * Devuelve a pendiente los pares que el defecto dejo compitiendo por un maestro.
     *
     * No borra nada, y no elige por criterio propio: se queda con el par mas viejo
     * de cada maestro porque es el unico desempate que no inventa informacion. Los
     * demas vuelven a ser una pregunta — que es exactamente lo que son, porque el
     * sistema ya no sabe cual de los dos pagos explicaba ese asiento — y su satelite
     * vuelve a contar para que ningun total quede corto mientras tanto.
     *
     * Un par confirmado sin maestro deducible sale por el mismo camino: `status` y
     * `master_transaction_id` tienen que quedar coherentes o el CHECK de arriba no
     * se puede crear.
     */
    private function demoteMastersClaimedTwice(): void
    {
        $extras = DB::select("
            SELECT id, transaction_id, candidate_transaction_id, master_transaction_id
            FROM (
                SELECT id, transaction_id, candidate_transaction_id, master_transaction_id,
                       row_number() OVER (PARTITION BY master_transaction_id ORDER BY id) AS n
                FROM reconciliation_candidates
                WHERE status = 'confirmed' AND kind = 'cross_source' AND master_transaction_id IS NOT NULL
            ) ranked
            WHERE n > 1

            UNION ALL

            SELECT id, transaction_id, candidate_transaction_id, master_transaction_id
            FROM reconciliation_candidates
            WHERE status = 'confirmed' AND master_transaction_id IS NULL
        ");

        foreach ($extras as $pair) {
            $satellites = array_filter(
                [$pair->transaction_id, $pair->candidate_transaction_id],
                fn (int $side): bool => $side !== $pair->master_transaction_id
            );

            DB::table('transactions')
                ->whereIn('id', $satellites)
                ->whereIn('matched_transaction_id', array_filter([$pair->master_transaction_id]))
                ->update(['matched_transaction_id' => null]);

            DB::table('reconciliation_candidates')->where('id', $pair->id)->update([
                'status' => 'pending',
                'resolved_by' => null,
                'resolved_at' => null,
                'master_transaction_id' => null,
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unq_reconciliation_candidates_cross_source_master');

        foreach (['kind', 'master_when_confirmed', 'master_belongs'] as $name) {
            DB::statement("ALTER TABLE reconciliation_candidates DROP CONSTRAINT IF EXISTS chk_reconciliation_candidates_{$name}");
        }

        Schema::table('reconciliation_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_transaction_id');
            $table->dropColumn('kind');
        });
    }
};
