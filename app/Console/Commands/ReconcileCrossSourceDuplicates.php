<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReconciliationStatus;
use App\Enums\SourceType;
use App\Models\ReconciliationCandidate;
use App\Models\Transaction;
use App\Services\Reconciliation\DuplicateCandidateDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;

/**
 * Pasa por `DuplicateCandidateDetector` las filas que nunca pasaron.
 *
 * El detector corre solo cuando una fila se escribe. Todo lo anterior al modulo
 * (2026-08-12), y todo lo que se inspecciono antes de que un maestro pudiera ser
 * absorbido, quedo con los dos lados sueltos y contando dos veces.
 *
 * No hay una regla propia aca. Se llama al MISMO `inspect()` que llaman los
 * importadores y la captura, asi que lo que se une hacia atras se une por las
 * mismas razones que lo que se une en vivo — y un arreglo al detector arregla las
 * dos cosas a la vez.
 *
 * El orden es la unica decision de este comando, y no es cosmetica: el detector
 * decide mirando lo que YA esta unido. Se inspecciona de menor a mayor autoridad
 * (captura, Excel de Yape, extracto) y, dentro de cada fuente, del movimiento mas
 * viejo al mas nuevo. Es el orden en que esas filas llegan en vivo, que es el
 * orden para el que las reglas se escribieron: la captura y el Excel se unen
 * primero por cercania de segundos, y cuando llega el extracto absorbe al Excel
 * por la regla estructural. Al reves, el extracto mira primero, su fila mas
 * cercana es la captura, y ese par nunca se decide solo — queda una pregunta
 * abierta y la cadena rota.
 */
class ReconcileCrossSourceDuplicates extends Command
{
    protected $signature = 'transactions:reconcile-cross-source-duplicates
        {--apply : Escribe los cambios en vez de solo informarlos}
        {--user= : Limita la corrida a un solo usuario}';

    protected $description = 'Pasa por el detector de duplicados entre fuentes los movimientos que nunca se inspeccionaron';

    /** Las fuentes para las que el detector corre en vivo. */
    private const INSPECTED_SOURCES = [
        SourceType::CAPTURE,
        SourceType::IMPORT_APP,
        SourceType::IMPORT_STATEMENT,
    ];

    public function handle(DuplicateCandidateDetector $detector): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? 'Aplicando: los enlaces se escriben.'
            : 'Simulación: nada de esto se escribe.');

        if (! $apply) {
            // El detector deja una linea de log por cada par que une. En una
            // simulacion esa linea diria que un movimiento dejo de contar cuando
            // nada cambio, y el log es justo donde se va a buscar que paso.
            Log::swap(new NullLogger);
        }

        // Una transaccion que se deshace al final. El detector decide mirando lo que
        // ya quedo unido, asi que simular fila por fila sin escribir mentiria: la
        // segunda fila del dia no veria que la primera ya tomo el asiento. Adentro
        // de la transaccion cada decision ve a las anteriores, igual que con
        // --apply, y el rollBack del `finally` se lleva todo — tambien si algo
        // revienta a mitad de camino.
        //
        // Las transacciones propias del detector se anidan como savepoints, y
        // Laravel no emite nada al "confirmar" un nivel anidado: solo el nivel de
        // afuera llega a la base, y ese nivel es este.
        if (! $apply) {
            DB::beginTransaction();
        }

        try {
            [$tally, $merged] = $this->inspectAll($detector);
        } finally {
            if (! $apply) {
                DB::rollBack();
            }
        }

        $this->report($tally, $merged, $apply);

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array{confirmed: int, pending: int, none: int}>, 1: list<array<int, string>>}
     */
    private function inspectAll(DuplicateCandidateDetector $detector): array
    {
        $tally = [];
        $merged = [];
        $paired = [];

        foreach ($this->inspectionOrder() as $id) {
            // La fila que una inspeccion anterior de esta corrida ya metio en un par
            // se salta: ese par ya se conto una vez, del lado que lo abrio, y el
            // detector no busca nada para una fila con un par abierto.
            if (isset($paired[$id])) {
                continue;
            }

            $transaction = Transaction::query()->find($id);

            if ($transaction === null) {
                continue;
            }

            $userId = (int) $transaction->user_id;
            $tally[$userId] ??= ['confirmed' => 0, 'pending' => 0, 'none' => 0];

            $record = $detector->inspect($transaction);

            if ($record !== null) {
                $paired[(int) $record->transaction_id] = true;
                $paired[(int) $record->candidate_transaction_id] = true;
            }

            if ($record === null) {
                $tally[$userId]['none']++;
            } elseif ($record->status === ReconciliationStatus::CONFIRMED) {
                $tally[$userId]['confirmed']++;
                $merged[] = $this->describe($record);
            } else {
                $tally[$userId]['pending']++;
            }
        }

        ksort($tally);

        return [$tally, $merged];
    }

    /**
     * Los ids a inspeccionar, en el orden de la docblock de la clase.
     *
     * Se ordena en PHP y no con un CASE en SQL para que la autoridad salga del
     * mismo `SourceType::authority()` que usa `ReconciliationLinker::rank()`: si
     * algun dia cambia el ranking, este orden cambia con el.
     *
     * @return list<int>
     */
    private function inspectionOrder(): array
    {
        return Transaction::query()
            ->select(['id', 'source_type', 'date_operation'])
            ->whereIn('source_type', array_map(static fn (SourceType $s): string => $s->value, self::INSPECTED_SOURCES))
            ->whereNull('matched_transaction_id')
            ->when($this->option('user'), fn ($query, $userId) => $query->where('user_id', (int) $userId))
            // Una fila que ya espera una respuesta de alguien no se vuelve a mirar:
            // la decision es de esa persona. Tampoco la que ya es maestro de un par
            // confirmado: el detector no busca nada para una fila con un par abierto,
            // y contarla como "sin pareja" mentiria. Las dos siguen a la vista como
            // CONTRAPARTE — asi es como el extracto absorbe a un Excel ya maestro.
            ->whereNotExists(function ($open): void {
                $open->select(DB::raw('1'))
                    ->from('reconciliation_candidates as rc')
                    ->whereIn('rc.status', [ReconciliationStatus::PENDING->value, ReconciliationStatus::CONFIRMED->value])
                    ->where(function ($side): void {
                        $side->whereColumn('rc.transaction_id', 'transactions.id')
                            ->orWhereColumn('rc.candidate_transaction_id', 'transactions.id');
                    });
            })
            ->get()
            ->sortBy([
                fn (Transaction $a, Transaction $b): int => SourceType::fromColumn($a->source_type)->authority()
                    <=> SourceType::fromColumn($b->source_type)->authority(),
                fn (Transaction $a, Transaction $b): int => strtotime((string) $a->date_operation)
                    <=> strtotime((string) $b->date_operation),
                fn (Transaction $a, Transaction $b): int => $a->id <=> $b->id,
            ])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function describe(ReconciliationCandidate $record): array
    {
        $rows = Transaction::query()
            ->select(['id', 'amount', 'type_transaction', 'date_operation', 'source_type'])
            ->whereKey([$record->transaction_id, $record->candidate_transaction_id])
            ->get()
            ->keyBy('id');

        $master = $rows->get($record->master_transaction_id);
        $satellite = $rows->first(fn (Transaction $t): bool => $t->id !== $record->master_transaction_id);

        return [
            (string) $master->id,
            (string) $satellite->id,
            (string) $master->type_transaction,
            'S/ '.number_format((float) $master->amount, 2),
            substr((string) $satellite->date_operation, 0, 10),
            "{$satellite->source_type} → {$master->source_type}",
        ];
    }

    /**
     * @param  array<int, array{confirmed: int, pending: int, none: int}>  $tally
     * @param  list<array<int, string>>  $merged
     */
    private function report(array $tally, array $merged, bool $apply): void
    {
        if ($tally === []) {
            $this->info('No hay movimientos sin inspeccionar.');
        }

        foreach ($tally as $userId => $counts) {
            $this->line(sprintf(
                'Usuario %d: %d unificado(s), %d pendiente(s), %d sin pareja.',
                $userId,
                $counts['confirmed'],
                $counts['pending'],
                $counts['none'],
            ));
        }

        $total = array_sum(array_column($tally, 'confirmed'));
        $this->line("Total: {$total} unificado(s) automaticamente.");

        if ($merged !== []) {
            $this->table(['Maestro', 'Satélite', 'Tipo', 'Monto', 'Fecha', 'Fuentes'], $merged);
        }

        if (! $apply) {
            // El modo informativo es el default a proposito, igual que en
            // `transactions:reconcile-import-duplicates`: esto cambia lo que suman
            // los reportes de alguien.
            $this->warn('Simulación: no se escribió nada. Vuelve a correrlo con --apply para escribir los cambios.');

            return;
        }

        $this->line('Revisables y reversibles desde /reconciliation-candidates/auto-merged.');
    }
}
