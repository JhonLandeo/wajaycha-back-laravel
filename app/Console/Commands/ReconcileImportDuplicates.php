<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReconciliationStatus;
use App\Enums\ResolvedBy;
use App\Enums\SourceType;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
 */
class ReconcileImportDuplicates extends Command
{
    protected $signature = 'transactions:reconcile-import-duplicates {--apply : Escribe los cambios en vez de solo informarlos}';

    protected $description = 'Concilia los movimientos que el importador de Yape duplicó antes de corregir su control de duplicados';

    public function handle(): int
    {
        $groups = $this->duplicateGroups();

        if ($groups === []) {
            $this->info('No hay duplicados de importación pendientes de conciliar.');

            return self::SUCCESS;
        }

        $satellites = array_sum(array_map(static fn (array $g): int => count($g['satellites']), $groups));

        // Gasto e ingreso se informan por separado y jamas sumados. Una sola cifra
        // mezclando los dos no significa nada -- restar mil de ingreso a dos mil de
        // gasto no describe ninguna magnitud real -- y en una herramienta de
        // finanzas un numero asi hace desconfiar de todos los demas.
        $this->info(sprintf('%d grupo(s), %d fila(s) de más.', count($groups), $satellites));

        foreach (['expense' => 'Gasto', 'income' => 'Ingreso'] as $type => $label) {
            $ofType = array_filter($groups, static fn (array $g): bool => $g['type'] === $type);

            if ($ofType === []) {
                continue;
            }

            $rows = array_sum(array_map(static fn (array $g): int => count($g['satellites']), $ofType));
            $amount = array_sum(array_map(static fn (array $g): float => $g['amount'] * count($g['satellites']), $ofType));

            $this->line(sprintf('  %-8s %2d fila(s), S/ %s contando doble.', $label, $rows, number_format($amount, 2)));
        }

        if (! $this->option('apply')) {
            // El modo informativo es el default a proposito. Esto cambia lo que
            // suman los reportes de alguien, y correrlo sin querer no deberia
            // poder pasar por olvidar una bandera.
            $this->warn('Simulación. Volvé a correrlo con --apply para escribir los cambios.');

            return self::SUCCESS;
        }

        $reconciled = 0;

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
     * @return array<int, array{user_id: int, master: int, satellites: int[], amount: float}>
     */
    private function duplicateGroups(): array
    {
        $rows = Transaction::query()
            ->select(['id', 'user_id', 'detail_id', 'amount', 'type_transaction', 'message', 'date_operation'])
            ->where('source_type', SourceType::IMPORT_APP->value)
            ->whereNull('matched_transaction_id')
            ->orderBy('date_operation')
            ->orderBy('id')
            ->get()
            // Misma clave que el control corregido del importador, incluida la
            // lectura de NULL y cadena vacia como el mismo mensaje ausente, y el
            // tipo: fusionar plata que entra con plata que sale seria el peor
            // error posible en un libro contable.
            ->groupBy(fn (Transaction $t): string => implode('|', [
                $t->user_id,
                $t->detail_id,
                $t->amount,
                $t->type_transaction,
                $t->message ?? '',
            ]));

        $groups = [];

        foreach ($rows as $sameMovement) {
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
}
