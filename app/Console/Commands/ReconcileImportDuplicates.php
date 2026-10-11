<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReconciliationKind;
use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Services\Reconciliation\ReconciliationLinker;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cleans up the rows the Yape importer duplicated before its duplicate check was
 * fixed.
 *
 * The check compared the message with `=` — so it did nothing at all for any
 * movement without a note, because in SQL neither `NULL = NULL` nor `NULL = ''`
 * is true — and compared the merchant against the raw text of the file rather
 * than the Detail that Entity Resolution had actually attached. Re-importing a
 * period already loaded therefore wrote the movement again. 142 pairs across 272
 * rows in production.
 *
 * This does NOT invent a rule. It reconciles exactly the rows the corrected
 * check would now reject, which is the narrowest defensible definition of the
 * damage: same user, same Detail, same amount, same source, within the same
 * sixty seconds, and the same message once NULL and empty string are read as the
 * one thing they mean.
 *
 * Nothing is deleted. The later row keeps existing and stops counting through
 * `matched_transaction_id`, and every pair is written to
 * `reconciliation_candidates` as resolved by the system — so the whole cleanup
 * shows up in the same "unified automatically" list as everything else and can
 * be undone one pair at a time.
 *
 * `DuplicateCandidateDetector` will never find these: it crosses different
 * sources on purpose, and this is Yape against Yape.
 *
 * It also reconciles a second shape of the same damage: a re-import whose
 * earlier twin is no longer visible because a bank statement already absorbed
 * it. Pairing visible rows alone never sees those — the twin stopped counting,
 * so the re-import looks like the only copy and sits next to the statement
 * counting twice. They exist because rows written before 630fc34 were stored
 * five hours early, and `alreadyImported()` could not recognise them when the
 * same period was exported again. See `reimportsOfAbsorbedRows()`.
 */
class ReconcileImportDuplicates extends Command
{
    protected $signature = 'transactions:reconcile-import-duplicates {--apply : Escribe los cambios en vez de solo informarlos}';

    protected $description = 'Concilia los movimientos que el importador de Yape duplicó antes de corregir su control de duplicados';

    public function __construct(private readonly ReconciliationLinker $linker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = $this->importRows();
        $reimports = $this->reimportsOfAbsorbedRows($rows);

        // Una reimportacion se va con su raiz y no entra a los grupos de visibles:
        // si dos reimportaciones del mismo movimiento se juntaran entre ellas, la
        // segunda quedaria apuntando a la primera y la primera a la raiz, una
        // cadena que este comando existe para no dejar.
        $reimported = array_flip(array_map(static fn (array $r): int => $r['row'], $reimports));
        $groups = $this->duplicateGroups($rows->filter(
            static fn (Transaction $t): bool => $t->matched_transaction_id === null && ! isset($reimported[(int) $t->id])
        ));

        if ($groups === [] && $reimports === []) {
            $this->info('No hay duplicados de importación pendientes de conciliar.');

            return self::SUCCESS;
        }

        if ($groups !== []) {
            $satellites = array_sum(array_map(static fn (array $g): int => count($g['satellites']), $groups));

            $this->info(sprintf('%d grupo(s), %d fila(s) de más.', count($groups), $satellites));
            $this->reportByType(array_map(static fn (array $g): array => [
                'rows' => count($g['satellites']),
                'amount' => $g['amount'],
                'type' => $g['type'],
            ], $groups));
        }

        if ($reimports !== []) {
            $this->info(sprintf('%d reimportación(es) de filas que ya eran satélite.', count($reimports)));
            $this->reportByType(array_map(static fn (array $r): array => [
                'rows' => 1,
                'amount' => $r['amount'],
                'type' => $r['type'],
            ], $reimports));
        }

        if (! $this->option('apply')) {
            // El modo informativo es el default a proposito. Esto cambia lo que
            // suman los reportes de alguien, y correrlo sin querer no deberia
            // poder pasar por olvidar una bandera.
            $this->warn('Simulación. Volvé a correrlo con --apply para escribir los cambios.');

            return self::SUCCESS;
        }

        $reconciled = 0;

        foreach ($reimports as $reimport) {
            $reconciled += $this->reconcileReimport($reimport);
        }

        foreach ($groups as $group) {
            $reconciled += $this->reconcile($group['user_id'], $group['master'], $group['satellites']);
        }

        $this->info("Se conciliaron {$reconciled} fila(s). Ninguna se borró: dejaron de contar.");
        $this->line('Revisables y reversibles desde /reconciliation-candidates/auto-merged.');

        return self::SUCCESS;
    }

    /** The importer's own tolerance, and therefore this command's. */
    private const TOLERANCE_SECONDS = 60;

    /**
     * Clusters of movements that are one import repeated.
     *
     * Built in PHP rather than with a `date_trunc('minute', …)` window, and the
     * difference is not cosmetic: measured against production, minute buckets
     * find 40 of the 41 pairs the sixty-second rule finds. The one they lose is
     * a pair straddling a minute boundary — 14:30:55 against 14:31:05, six
     * seconds apart and in two different buckets. Reconciling 40 of 41 known
     * duplicates is not a rounding error when the residue is money left counting
     * twice.
     *
     * Every row in a cluster is measured against its MASTER, never against its
     * neighbour. A group of three has to collapse onto one survivor, not into a
     * chain where a satellite points at a row that is itself a satellite:
     * `fn_get_transactions` counts rows whose `matched_transaction_id` is null,
     * so a chain drops the far end out of the totals altogether.
     *
     * `type` viaja en cada grupo porque gasto e ingreso se informan por separado
     * y jamas sumados. Estuvo ausente de esta firma mientras el codigo si lo
     * escribia, y el analisis estatico —que solo puede creerle a la firma—
     * dedujo que el desglose por tipo era codigo muerto.
     *
     * @param  Collection<int, Transaction>  $visible
     * @return array<int, array{user_id: int, master: int, satellites: int[], amount: float, type: string}>
     */
    private function duplicateGroups(Collection $visible): array
    {
        $groups = [];

        foreach ($visible->groupBy(fn (Transaction $t): string => $this->movementKey($t)) as $sameMovement) {
            $master = null;

            foreach ($sameMovement as $row) {
                if ($master === null || $this->secondsBetween($master, $row) > self::TOLERANCE_SECONDS) {
                    $master = $row;
                    $groups[$master->id] = [
                        'user_id' => (int) $row->user_id,
                        'master' => (int) $row->id,
                        'satellites' => [],
                        'amount' => (float) $row->amount,
                        'type' => (string) $row->type_transaction,
                    ];

                    continue;
                }

                $groups[$master->id]['satellites'][] = (int) $row->id;
            }
        }

        return array_values(array_filter($groups, static fn (array $group): bool => $group['satellites'] !== []));
    }

    /**
     * Every Yape row, visible or not, in the order the clustering needs.
     *
     * One read serves both passes: the visible rows are the ones that may still
     * be paired, the satellites are the twins a re-import is measured against.
     *
     * @return Collection<int, Transaction>
     */
    private function importRows(): Collection
    {
        return Transaction::query()
            ->select(['id', 'user_id', 'detail_id', 'amount', 'type_transaction', 'message', 'date_operation', 'matched_transaction_id'])
            ->where('source_type', SourceType::IMPORT_APP->value)
            ->orderBy('date_operation')
            ->orderBy('id')
            ->get();
    }

    /**
     * Misma clave que el control corregido del importador, incluida la lectura de
     * NULL y cadena vacia como el mismo mensaje ausente, y el tipo: fusionar plata
     * que entra con plata que sale seria el peor error posible en un libro
     * contable.
     */
    private function movementKey(Transaction $t): string
    {
        return implode('|', [
            $t->user_id,
            $t->detail_id,
            $t->amount,
            $t->type_transaction,
            $t->message ?? '',
        ]);
    }

    /**
     * Visible re-imports whose twin already stopped counting.
     *
     * Same key and same sixty seconds as the visible pass, but the twin is a
     * satellite, so the re-import goes to the ROOT of the twin's chain — the row
     * that actually counts — and never to the twin itself, which would leave a
     * satellite pointing at a satellite.
     *
     * The closest twin decides. A twin whose chain leads back to the re-import
     * itself is not a twin of anything else: it is that row's own satellite.
     *
     * @param  Collection<int, Transaction>  $rows
     * @return list<array{user_id: int, row: int, twin: int, root: int, amount: float, type: string}>
     */
    private function reimportsOfAbsorbedRows(Collection $rows): array
    {
        $parents = [];

        foreach ($rows as $row) {
            $parents[(int) $row->id] = [
                'user_id' => (int) $row->user_id,
                'master_id' => $row->matched_transaction_id === null ? null : (int) $row->matched_transaction_id,
            ];
        }

        $reimports = [];

        foreach ($rows->groupBy(fn (Transaction $t): string => $this->movementKey($t)) as $sameMovement) {
            $absorbed = $sameMovement->filter(static fn (Transaction $t): bool => $t->matched_transaction_id !== null);

            if ($absorbed->isEmpty()) {
                continue;
            }

            foreach ($sameMovement as $row) {
                if ($row->matched_transaction_id !== null) {
                    continue;
                }

                $twins = $absorbed
                    ->filter(fn (Transaction $twin): bool => $this->secondsBetween($row, $twin) <= self::TOLERANCE_SECONDS)
                    ->sortBy([
                        fn (Transaction $a, Transaction $b): int => $this->secondsBetween($row, $a) <=> $this->secondsBetween($row, $b),
                        static fn (Transaction $a, Transaction $b): int => $a->id <=> $b->id,
                    ]);

                foreach ($twins as $twin) {
                    $root = $this->rootOf((int) $twin->id, (int) $row->id, $parents);

                    // Una raiz de otro usuario no deberia existir -- las claves
                    // foraneas cruzadas lo impiden --, pero si existiera, unirla
                    // moveria plata entre libros ajenos. Se salta.
                    if ($root === null || $parents[$root]['user_id'] !== (int) $row->user_id) {
                        continue;
                    }

                    // El detector cruzado ya pregunto por la raiz y esta fila, y el
                    // usuario dijo que son movimientos distintos. Esa respuesta pesa
                    // mas que la coincidencia con la gemela.
                    if ($this->userSeparated($root, (int) $row->id)) {
                        continue;
                    }

                    $reimports[] = [
                        'user_id' => (int) $row->user_id,
                        'row' => (int) $row->id,
                        'twin' => (int) $twin->id,
                        'root' => $root,
                        'amount' => (float) $row->amount,
                        'type' => (string) $row->type_transaction,
                    ];

                    break;
                }
            }
        }

        return $reimports;
    }

    /**
     * Follows `matched_transaction_id` to the row that counts.
     *
     * Masters of another source (the bank statement, usually) were not in the
     * Yape read; they are fetched by primary key as the walk reaches them.
     * Returns null when the walk passes through `$excluded` or loops.
     *
     * @param  array<int, array{user_id: int, master_id: int|null}>  $parents
     */
    private function rootOf(int $id, int $excluded, array &$parents): ?int
    {
        $seen = [];

        while (true) {
            if ($id === $excluded || isset($seen[$id])) {
                return null;
            }

            $seen[$id] = true;

            if (! isset($parents[$id])) {
                $master = Transaction::query()->select(['id', 'user_id', 'matched_transaction_id'])->find($id);

                if ($master === null) {
                    return null;
                }

                $parents[$id] = [
                    'user_id' => (int) $master->user_id,
                    'master_id' => $master->matched_transaction_id === null ? null : (int) $master->matched_transaction_id,
                ];
            }

            if ($parents[$id]['master_id'] === null) {
                return $id;
            }

            $id = $parents[$id]['master_id'];
        }
    }

    /**
     * Gasto e ingreso se informan por separado y jamas sumados. Una sola cifra
     * mezclando los dos no significa nada -- restar mil de ingreso a dos mil de
     * gasto no describe ninguna magnitud real -- y en una herramienta de
     * finanzas un numero asi hace desconfiar de todos los demas.
     *
     * @param  array<int, array{rows: int, amount: float, type: string}>  $items
     */
    private function reportByType(array $items): void
    {
        foreach (['expense' => 'Gasto', 'income' => 'Ingreso'] as $type => $label) {
            $ofType = array_filter($items, static fn (array $i): bool => $i['type'] === $type);

            if ($ofType === []) {
                continue;
            }

            $rows = array_sum(array_map(static fn (array $i): int => $i['rows'], $ofType));
            $amount = array_sum(array_map(static fn (array $i): float => $i['amount'] * $i['rows'], $ofType));

            $this->line(sprintf('  %-8s %2d fila(s), S/ %s contando doble.', $label, $rows, number_format($amount, 2)));
        }
    }

    private function secondsBetween(Transaction $a, Transaction $b): int
    {
        return (int) Carbon::parse($a->date_operation)
            ->diffInSeconds(Carbon::parse($b->date_operation), absolute: true);
    }

    /**
     * @param  int[]  $satelliteIds
     */
    private function reconcile(int $userId, int $masterId, array $satelliteIds): int
    {
        return DB::transaction(function () use ($userId, $masterId, $satelliteIds): int {
            $done = 0;

            foreach ($satelliteIds as $satelliteId) {
                try {
                    // Savepoint propio, por la misma razon que
                    // `ChannelLinkTokenRedeemer` ya documenta: en PostgreSQL una
                    // violacion de unicidad sin savepoint aborta la transaccion
                    // entera, y aca la primera colision se llevaria puesto todo el
                    // grupo -- incluidos los pares que si habia que conciliar.
                    DB::transaction(fn () => ReconciliationCandidate::create([
                        'user_id' => $userId,
                        'transaction_id' => $masterId,
                        'candidate_transaction_id' => $satelliteId,
                        // Los grupos de este comando son de UNA sola fuente por
                        // construccion — se agrupan por usuario, Detail, monto, fuente
                        // y minuto — y por eso un grupo de tres colapsa sobre un
                        // sobreviviente con dos satelites. Marcarlos asi es lo que
                        // mantiene a `unq_reconciliation_candidates_cross_source_master`
                        // fuera de esta operacion, donde seria incorrecto.
                        'kind' => ReconciliationKind::SAME_SOURCE,
                        'master_transaction_id' => $masterId,
                        'status' => ReconciliationStatus::CONFIRMED,
                        'resolved_by' => ResolvedBy::SYSTEM,
                        'resolved_at' => now(),
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // El par ya fue juzgado alguna vez. Si el usuario lo separo a
                    // mano, volver a unirlo seria pisar su decision con la nuestra.
                    continue;
                }

                Transaction::whereKey($satelliteId)->update(['matched_transaction_id' => $masterId]);
                $done++;
            }

            return $done;
        });
    }

    /**
     * El par que se registra es la reimportacion contra su gemela, Yape contra
     * Yape, y por eso misma fuente. Registrarlo contra la raiz lo volveria un
     * segundo cruce sobre el mismo asiento del extracto, que
     * `unq_reconciliation_candidates_cross_source_master` prohibe con razon. La
     * fila, en cambio, apunta a la raiz: es la unica forma de no dejar cadena.
     * Deshacerlo funciona igual: entre dos filas de Yape el linker suelta la de id
     * mas alto, que es la reimportacion.
     *
     * @param  array{user_id: int, row: int, twin: int, root: int, amount: float, type: string}  $reimport
     */
    private function reconcileReimport(array $reimport): int
    {
        return DB::transaction(function () use ($reimport): int {
            try {
                // Savepoint propio por la misma razon que en `reconcile()`.
                DB::transaction(fn () => ReconciliationCandidate::create([
                    'user_id' => $reimport['user_id'],
                    'transaction_id' => $reimport['twin'],
                    'candidate_transaction_id' => $reimport['row'],
                    'kind' => ReconciliationKind::SAME_SOURCE,
                    'master_transaction_id' => $reimport['twin'],
                    'status' => ReconciliationStatus::CONFIRMED,
                    'resolved_by' => ResolvedBy::SYSTEM,
                    'resolved_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                // El par ya fue juzgado. Si el usuario lo separo, gana el.
                return 0;
            }

            $rows = Transaction::query()
                ->select(['id', 'category_id', 'matched_transaction_id'])
                ->whereKey([$reimport['row'], $reimport['root']])
                ->get()
                ->keyBy('id');

            $row = $rows->get($reimport['row']);
            $root = $rows->get($reimport['root']);

            $row->update(['matched_transaction_id' => $reimport['root']]);
            $this->linker->inheritCategory($root, $row);

            return 1;
        });
    }

    private function userSeparated(int $a, int $b): bool
    {
        return ReconciliationCandidate::query()
            ->where('status', ReconciliationStatus::REJECTED)
            ->where(fn ($pair) => $pair
                ->where(fn ($q) => $q->where('transaction_id', $a)->where('candidate_transaction_id', $b))
                ->orWhere(fn ($q) => $q->where('transaction_id', $b)->where('candidate_transaction_id', $a)))
            ->exists();
    }
}
