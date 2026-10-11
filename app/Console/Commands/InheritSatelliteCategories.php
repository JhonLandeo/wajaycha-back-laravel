<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pasa al maestro la categoria que quedo en su satelite.
 *
 * `ReconciliationLinker::link()` ahora llena la categoria del maestro cuando la
 * tiene el satelite. Los pares unidos antes de ese arreglo quedaron con la
 * categoria escondida: la puso alguien sobre el Excel de Yape, el extracto que se
 * ve dice "YAPE JOSE TOR" y aparece sin categoria en los reportes.
 *
 * Misma regla que el linker: solo se llena un vacio, nunca se pisa una categoria
 * que el maestro ya tiene. Lo unico propio de este comando son las cadenas y los
 * conflictos:
 *
 * - Cadena (captura -> Excel -> extracto). Se resuelve de abajo hacia arriba: el
 *   Excel sin categoria hereda la de su captura y el extracto hereda la del Excel.
 *   Es el orden en que el linker las pasa en vivo, asi que el satelite mas cercano
 *   es el que decide.
 * - Conflicto. Un maestro sin categoria cuyos satelites traen categorias
 *   distintas no hereda nada: elegir una seria adivinar. Se informa para que la
 *   decida una persona.
 */
class InheritSatelliteCategories extends Command
{
    protected $signature = 'transactions:inherit-satellite-categories
        {--apply : Escribe los cambios en vez de solo informarlos}
        {--user= : Limita la corrida a un solo usuario}';

    protected $description = 'Pasa a los maestros sin categoria la categoria de sus satelites unidos antes del arreglo del linker';

    /** @var array<int, array{user_id: int, category_id: int|null, master_id: int|null}> */
    private array $rows = [];

    /** @var array<int, list<int>> maestro => sus satelites directos */
    private array $satellitesOf = [];

    /** @var array<int, int|null> categoria con la que la fila termina visible */
    private array $resolved = [];

    /** @var array<int, true> filas en recorrido, para no colgarse en un ciclo */
    private array $visiting = [];

    /** @var array<int, int> maestro => categoria que hereda */
    private array $inherits = [];

    /** @var array<int, list<int>> maestro => categorias distintas de sus satelites */
    private array $conflicts = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? 'Aplicando: las categorias se escriben.'
            : 'Simulación: nada de esto se escribe.');

        $this->loadLinkedRows();

        foreach (array_keys($this->satellitesOf) as $masterId) {
            $this->resolve($masterId);
        }

        if ($apply && $this->inherits !== []) {
            DB::transaction(function (): void {
                foreach ($this->inherits as $masterId => $categoryId) {
                    // El whereNull repite la regla en la escritura: si alguien
                    // categorizo la fila mientras corria esto, gana esa persona.
                    Transaction::query()
                        ->whereKey($masterId)
                        ->whereNull('category_id')
                        ->update(['category_id' => $categoryId]);
                }
            });
        }

        $this->report($apply);

        return self::SUCCESS;
    }

    /**
     * Todas las filas de algun enlace: los satelites y los maestros a los que
     * apuntan. Se lee por `matched_transaction_id` y por clave primaria, sin
     * funciones sobre las columnas.
     */
    private function loadLinkedRows(): void
    {
        $columns = ['id', 'user_id', 'category_id', 'matched_transaction_id'];

        $satellites = Transaction::query()
            ->select($columns)
            ->whereNotNull('matched_transaction_id')
            ->when($this->option('user'), fn ($query, $userId) => $query->where('user_id', (int) $userId))
            ->get();

        $this->remember($satellites);

        $missingMasters = array_values(array_diff(
            $satellites->pluck('matched_transaction_id')->map(static fn ($id): int => (int) $id)->unique()->all(),
            array_keys($this->rows),
        ));

        if ($missingMasters !== []) {
            $this->remember(Transaction::query()->select($columns)->whereKey($missingMasters)->get());
        }

        foreach ($this->rows as $id => $row) {
            if ($row['master_id'] !== null) {
                $this->satellitesOf[$row['master_id']][] = $id;
            }
        }
    }

    /** @param  iterable<Transaction>  $transactions */
    private function remember(iterable $transactions): void
    {
        foreach ($transactions as $transaction) {
            $this->rows[(int) $transaction->id] = [
                'user_id' => (int) $transaction->user_id,
                'category_id' => $transaction->category_id === null ? null : (int) $transaction->category_id,
                'master_id' => $transaction->matched_transaction_id === null ? null : (int) $transaction->matched_transaction_id,
            ];
        }
    }

    /**
     * La categoria con la que la fila queda despues de heredar, de abajo hacia
     * arriba. Una fila en conflicto queda sin categoria y no le pasa nada a su
     * propio maestro: el conflicto no se esconde un nivel mas arriba.
     */
    private function resolve(int $id): ?int
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        $own = $this->rows[$id]['category_id'] ?? null;

        if ($own !== null || isset($this->visiting[$id])) {
            return $this->resolved[$id] = $own;
        }

        $this->visiting[$id] = true;

        $candidates = [];

        foreach ($this->satellitesOf[$id] ?? [] as $satelliteId) {
            $category = $this->resolve($satelliteId);

            if ($category !== null) {
                $candidates[$category] = true;
            }
        }

        unset($this->visiting[$id]);

        $candidates = array_keys($candidates);

        if (count($candidates) > 1) {
            sort($candidates);
            $this->conflicts[$id] = $candidates;

            return $this->resolved[$id] = null;
        }

        if ($candidates === []) {
            return $this->resolved[$id] = null;
        }

        $this->inherits[$id] = $candidates[0];

        return $this->resolved[$id] = $candidates[0];
    }

    private function report(bool $apply): void
    {
        $names = Category::query()
            ->select(['id', 'name'])
            ->whereKey(array_unique([...array_values($this->inherits), ...array_merge([], ...array_values($this->conflicts))]))
            ->pluck('name', 'id');

        $label = static fn (int $categoryId): string => "{$categoryId} ".($names[$categoryId] ?? '?');

        $this->line(sprintf(
            'Total: %d maestro(s) heredan categoria, %d en conflicto.',
            count($this->inherits),
            count($this->conflicts),
        ));

        if ($this->inherits !== []) {
            $rows = [];
            foreach ($this->inherits as $masterId => $categoryId) {
                $rows[] = [
                    (string) $this->rows[$masterId]['user_id'],
                    (string) $masterId,
                    $label($categoryId),
                    implode(', ', $this->satellitesOf[$masterId]),
                ];
            }
            $this->table(['Usuario', 'Maestro', 'Categoría', 'Satélites'], $rows);
        }

        if ($this->conflicts !== []) {
            $this->warn('Maestros en conflicto: sus satelites traen categorias distintas y no se toca ninguno.');

            $rows = [];
            foreach ($this->conflicts as $masterId => $categoryIds) {
                $rows[] = [
                    (string) $this->rows[$masterId]['user_id'],
                    (string) $masterId,
                    implode(' | ', array_map($label, $categoryIds)),
                    implode(', ', $this->satellitesOf[$masterId]),
                ];
            }
            $this->table(['Usuario', 'Maestro', 'Categorías', 'Satélites'], $rows);
        }

        if (! $apply) {
            $this->warn('Simulación: no se escribió nada. Vuelve a correrlo con --apply para escribir los cambios.');
        }
    }
}
