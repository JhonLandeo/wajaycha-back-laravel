<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\MealEntryKind;
use App\Models\MealPlanEntry;
use App\Models\MealPlanSettings;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class MealPlanRepository implements MealPlanRepositoryContract
{
    public function settingsFor(int $userId): MealPlanSettings
    {
        /** @var MealPlanSettings $settings */
        $settings = MealPlanSettings::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'household_size' => 2,
                'meal_types' => ['breakfast', 'lunch', 'dinner'],
                'plan_weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'excluded_terms' => [],
                'max_prep_minutes' => null,
            ]
        );

        return $settings;
    }

    public function updateSettings(MealPlanSettings $settings, array $data): MealPlanSettings
    {
        $settings->update($data);

        return $settings->refresh();
    }

    public function entriesForWeek(int $userId, CarbonImmutable $startsOn, CarbonImmutable $endsOn): Collection
    {
        return MealPlanEntry::query()
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [$startsOn->toDateString(), $endsOn->toDateString()])
            ->with(['recipe.ingredients.product', 'leftoverOf.recipe'])
            ->orderBy('plan_date')
            ->orderByRaw("CASE meal_type WHEN 'breakfast' THEN 1 WHEN 'lunch' THEN 2 ELSE 3 END")
            ->get();
    }

    public function findEntry(int $id, int $userId): ?MealPlanEntry
    {
        /** @var MealPlanEntry|null $entry */
        $entry = MealPlanEntry::query()
            ->whereKey($id)
            ->where('user_id', $userId)
            ->with(['recipe.ingredients.product', 'leftoverOf.recipe'])
            ->first();

        return $entry;
    }

    public function findSlot(int $userId, string $planDate, \App\Enums\MealType $mealType): ?MealPlanEntry
    {
        /** @var MealPlanEntry|null $entry */
        $entry = MealPlanEntry::query()
            ->where('user_id', $userId)
            ->where('plan_date', $planDate)
            ->where('meal_type', $mealType->value)
            ->with(['recipe.ingredients.product', 'leftoverOf.recipe'])
            ->first();

        return $entry;
    }

    public function upsertEntry(int $userId, array $data): MealPlanEntry
    {
        /** @var MealPlanEntry $entry */
        $entry = MealPlanEntry::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'plan_date' => $data['plan_date'],
                'meal_type' => $data['meal_type'],
            ],
            [
                'kind' => $data['kind'],
                'status' => $data['status'] ?? 'planned',
                'recipe_id' => $data['recipe_id'] ?? null,
                'servings' => $data['servings'] ?? 0,
                'cook_servings' => $data['cook_servings'] ?? 0,
                'leftover_of_entry_id' => $data['leftover_of_entry_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        );

        return $entry->load(['recipe.ingredients.product', 'leftoverOf.recipe']);
    }

    public function deleteEntry(MealPlanEntry $entry): bool
    {
        return (bool) $entry->delete();
    }

    public function updateStatus(MealPlanEntry $entry, \App\Enums\MealEntryStatus $status): MealPlanEntry
    {
        $entry->update(['status' => $status->value]);

        return $entry->refresh();
    }

    public function clearWeek(int $userId, CarbonImmutable $startsOn, CarbonImmutable $endsOn): void
    {
        MealPlanEntry::query()
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [$startsOn->toDateString(), $endsOn->toDateString()])
            ->delete();
    }

    public function leftoverServingsUsed(int $sourceEntryId, ?int $exceptEntryId = null): int
    {
        return (int) MealPlanEntry::query()
            ->where('leftover_of_entry_id', $sourceEntryId)
            ->where('kind', MealEntryKind::Leftover->value)
            ->when($exceptEntryId !== null, fn ($query) => $query->whereKeyNot($exceptEntryId))
            ->sum('servings');
    }
}
