<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\MealPlanEntry;
use App\Models\MealPlanSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

interface MealPlanRepositoryContract
{
    /** Returns (creating if needed) the user's menu settings. */
    public function settingsFor(int $userId): MealPlanSettings;

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(MealPlanSettings $settings, array $data): MealPlanSettings;

    /**
     * @return Collection<int, MealPlanEntry>
     */
    public function entriesForWeek(int $userId, CarbonImmutable $startsOn, CarbonImmutable $endsOn): Collection;

    public function findEntry(int $id, int $userId): ?MealPlanEntry;

    public function findSlot(int $userId, string $planDate, \App\Enums\MealType $mealType): ?MealPlanEntry;

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertEntry(int $userId, array $data): MealPlanEntry;

    public function deleteEntry(MealPlanEntry $entry): bool;

    public function updateStatus(MealPlanEntry $entry, \App\Enums\MealEntryStatus $status): MealPlanEntry;

    public function clearWeek(int $userId, CarbonImmutable $startsOn, CarbonImmutable $endsOn): void;

    /**
     * Delete the entries of a week from a date onwards, preserving the history
     * that already happened (`plan_date` before `$from`).
     */
    public function clearWeekFrom(int $userId, CarbonImmutable $startsOn, CarbonImmutable $endsOn, CarbonImmutable $from): void;

    public function clearDay(int $userId, CarbonImmutable $date): void;

    /** Servings already claimed by leftover entries of a source entry. */
    public function leftoverServingsUsed(int $sourceEntryId, ?int $exceptEntryId = null): int;

    /**
     * Recipe entries that still have unused cooked portions and can feed the
     * target slot: any earlier day, plus earlier meals on the same day. The
     * window is bounded so an old unclaimed surplus is not offered forever.
     * `claimed_servings` comes from a `withSum`.
     *
     * @return Collection<int, MealPlanEntry>
     */
    public function leftoverSourcesFor(
        int $userId,
        string $planDate,
        \App\Enums\MealType $mealType,
        ?int $exceptEntryId = null,
    ): Collection;

    /**
     * Entries on other days that point to one of the given source entries.
     *
     * @param  array<int, int>  $sourceEntryIds
     * @return Collection<int, MealPlanEntry>
     */
    public function leftoversReferencing(int $userId, array $sourceEntryIds): Collection;

    /** Intercambia todas las entradas de dos fechas del mismo usuario. */
    public function swapDays(int $userId, CarbonImmutable $dateA, CarbonImmutable $dateB): void;

    /** Intercambia o mueve una comida entre dos fechas. */
    public function swapEntries(int $userId, CarbonImmutable $dateA, CarbonImmutable $dateB, \App\Enums\MealType $meal): void;

    /**
     * Entradas aplicadas en una ventana, con su receta e ingredientes, para
     * construir el perfil de preferencias.
     *
     * @return Collection<int, MealPlanEntry>
     */
    public function preferenceHistoryFor(int $userId, CarbonImmutable $since, CarbonImmutable $until): Collection;

    /**
     * Meals from the window whose planned dish was not cooked and that were not
     * reprogrammed yet. `leftover` and `ate_out` are included on purpose: they
     * are alternatives already eaten, not cooked dishes, and the UI offers to
     * reprogram them manually.
     *
     * @return Collection<int, MealPlanEntry>
     */
    public function pendingEntriesFor(int $userId, CarbonImmutable $since, CarbonImmutable $until): Collection;
}
