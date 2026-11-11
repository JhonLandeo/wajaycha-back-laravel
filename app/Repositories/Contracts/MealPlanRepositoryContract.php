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

    public function clearWeek(int $userId, CarbonImmutable $startsOn, CarbonImmutable $endsOn): void;

    /** Servings already claimed by leftover entries of a source entry. */
    public function leftoverServingsUsed(int $sourceEntryId, ?int $exceptEntryId = null): int;
}
