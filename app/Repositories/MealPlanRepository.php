<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\MealEntryKind;
use App\Enums\MealEntryStatus;
use App\Enums\MealType;
use App\Models\MealPlanEntry;
use App\Models\MealPlanSettings;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class MealPlanRepository implements MealPlanRepositoryContract
{
    /** Days of history offered as leftover sources when planning a meal. */
    private const LEFTOVER_SOURCE_LOOKBACK_DAYS = 14;

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
                'menu_strategy' => \App\Enums\MenuStrategy::Balanced->value,
                'available_equipment' => null,
                'diet_profile' => \App\Enums\DietProfile::Balanced->value,
                'new_recipes_per_week' => 5,
            ]
        );

        return $settings->refresh();
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
            ->with(['recipe.ingredients.product', 'drinkRecipe.ingredients.product', 'leftoverOf.recipe', 'rescheduledFrom'])
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
            ->with(['recipe.ingredients.product', 'drinkRecipe.ingredients.product', 'leftoverOf.recipe', 'rescheduledFrom'])
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
            ->with(['recipe.ingredients.product', 'drinkRecipe.ingredients.product', 'leftoverOf.recipe', 'rescheduledFrom'])
            ->first();

        return $entry;
    }

    public function upsertEntry(int $userId, array $data): MealPlanEntry
    {
        $attributes = [
            'kind' => $data['kind'],
            'status' => $data['status'] ?? 'planned',
            'recipe_id' => $data['recipe_id'] ?? null,
            'drink_recipe_id' => $data['drink_recipe_id'] ?? null,
            'servings' => $data['servings'] ?? 0,
            'cook_servings' => $data['cook_servings'] ?? 0,
            'leftover_of_entry_id' => $data['leftover_of_entry_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if (array_key_exists('rescheduled_from_entry_id', $data)) {
            $attributes['rescheduled_from_entry_id'] = $data['rescheduled_from_entry_id'];
        }

        /** @var MealPlanEntry $entry */
        $entry = MealPlanEntry::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'plan_date' => $data['plan_date'],
                'meal_type' => $data['meal_type'],
            ],
            $attributes,
        );

        return $entry->load([
            'recipe.ingredients.product',
            'drinkRecipe.ingredients.product',
            'leftoverOf.recipe',
            'rescheduledFrom',
        ]);
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

    public function clearWeekFrom(int $userId, CarbonImmutable $startsOn, CarbonImmutable $endsOn, CarbonImmutable $from): void
    {
        $fromDate = $from->setTimezone('America/Lima')->startOfDay();
        $start = $fromDate->greaterThan($startsOn) ? $fromDate : $startsOn;

        MealPlanEntry::query()
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [$start->toDateString(), $endsOn->toDateString()])
            ->delete();
    }

    public function clearDay(int $userId, CarbonImmutable $date): void
    {
        MealPlanEntry::query()
            ->where('user_id', $userId)
            ->whereDate('plan_date', $date->toDateString())
            ->delete();
    }

    public function leftoversReferencing(int $userId, array $sourceEntryIds): Collection
    {
        if ($sourceEntryIds === []) {
            return new Collection;
        }

        return MealPlanEntry::query()
            ->where('user_id', $userId)
            ->whereIn('leftover_of_entry_id', $sourceEntryIds)
            ->get();
    }

    public function preferenceHistoryFor(int $userId, CarbonImmutable $since, CarbonImmutable $until): Collection
    {
        return MealPlanEntry::query()
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [$since->toDateString(), $until->toDateString()])
            ->whereIn('kind', [MealEntryKind::Recipe->value, MealEntryKind::Leftover->value])
            ->whereIn('status', [
                MealEntryStatus::Planned->value,
                MealEntryStatus::Cooked->value,
                MealEntryStatus::Leftover->value,
                MealEntryStatus::Skipped->value,
            ])
            ->with(['recipe.ingredients.product'])
            ->get();
    }

    public function pendingEntriesFor(int $userId, CarbonImmutable $since, CarbonImmutable $until): Collection
    {
        return MealPlanEntry::query()
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [$since->toDateString(), $until->toDateString()])
            ->where('kind', MealEntryKind::Recipe->value)
            ->whereNotNull('recipe_id')
            ->whereIn('status', [
                MealEntryStatus::Planned->value,
                MealEntryStatus::Skipped->value,
                MealEntryStatus::Leftover->value,
                MealEntryStatus::AteOut->value,
            ])
            ->whereDoesntHave('rescheduledTo')
            ->with(['recipe.ingredients.product', 'drinkRecipe.ingredients.product', 'leftoverOf.recipe', 'rescheduledFrom'])
            ->orderByDesc('plan_date')
            ->orderByRaw("CASE meal_type WHEN 'breakfast' THEN 1 WHEN 'lunch' THEN 2 ELSE 3 END")
            ->get();
    }

    public function swapDays(int $userId, CarbonImmutable $dateA, CarbonImmutable $dateB): void
    {
        if ($dateA->isSameDay($dateB)) {
            return;
        }

        DB::transaction(function () use ($userId, $dateA, $dateB): void {
            $temporary = $this->temporaryDateFor($userId);

            MealPlanEntry::query()
                ->where('user_id', $userId)
                ->where('plan_date', $dateA->toDateString())
                ->update(['plan_date' => $temporary->toDateString()]);

            MealPlanEntry::query()
                ->where('user_id', $userId)
                ->where('plan_date', $dateB->toDateString())
                ->update(['plan_date' => $dateA->toDateString()]);

            MealPlanEntry::query()
                ->where('user_id', $userId)
                ->where('plan_date', $temporary->toDateString())
                ->update(['plan_date' => $dateB->toDateString()]);
        });
    }

    public function swapEntries(int $userId, CarbonImmutable $dateA, CarbonImmutable $dateB, \App\Enums\MealType $meal): void
    {
        if ($dateA->isSameDay($dateB)) {
            return;
        }

        $first = $this->findSlot($userId, $dateA->toDateString(), $meal);
        $second = $this->findSlot($userId, $dateB->toDateString(), $meal);

        if ($first === null && $second === null) {
            return;
        }

        DB::transaction(function () use ($userId, $first, $second, $dateA, $dateB): void {
            if ($first !== null && $second !== null) {
                $temporary = $this->temporaryDateFor($userId);

                MealPlanEntry::query()->whereKey($first->id)->update(['plan_date' => $temporary->toDateString()]);
                MealPlanEntry::query()->whereKey($second->id)->update(['plan_date' => $dateA->toDateString()]);
                MealPlanEntry::query()->whereKey($first->id)->update(['plan_date' => $dateB->toDateString()]);

                return;
            }

            $entry = $first ?? $second;
            $target = $first !== null ? $dateB : $dateA;
            MealPlanEntry::query()->whereKey($entry->id)->update(['plan_date' => $target->toDateString()]);
        });
    }

    private function temporaryDateFor(int $userId): CarbonImmutable
    {
        $temporary = CarbonImmutable::parse('1900-01-01', 'America/Lima');

        while (MealPlanEntry::query()->where('user_id', $userId)->where('plan_date', $temporary->toDateString())->exists()) {
            $temporary = $temporary->addDay();
        }

        return $temporary;
    }

    public function leftoverServingsUsed(int $sourceEntryId, ?int $exceptEntryId = null): int
    {
        return (int) MealPlanEntry::query()
            ->where('leftover_of_entry_id', $sourceEntryId)
            ->where('kind', MealEntryKind::Leftover->value)
            ->when($exceptEntryId !== null, fn ($query) => $query->whereKeyNot($exceptEntryId))
            ->sum('servings');
    }

    public function leftoverSourcesFor(
        int $userId,
        string $planDate,
        MealType $mealType,
        ?int $exceptEntryId = null,
    ): Collection {
        $from = CarbonImmutable::parse($planDate)->subDays(self::LEFTOVER_SOURCE_LOOKBACK_DAYS);
        $earlierMeals = array_values(array_filter(
            MealType::values(),
            static fn (string $meal): bool => MealType::from($meal)->order() < $mealType->order(),
        ));

        return MealPlanEntry::query()
            ->where('user_id', $userId)
            ->where('kind', MealEntryKind::Recipe->value)
            ->whereNotNull('recipe_id')
            ->where('plan_date', '>=', $from->toDateString())
            ->where('plan_date', '<=', $planDate)
            ->where(function ($query) use ($planDate, $earlierMeals): void {
                $query->where('plan_date', '<', $planDate);

                if ($earlierMeals !== []) {
                    $query->orWhere(static fn ($sameDay) => $sameDay
                        ->where('plan_date', $planDate)
                        ->whereIn('meal_type', $earlierMeals));
                }
            })
            ->whereColumn('cook_servings', '>', 'servings')
            ->with(['recipe'])
            ->withSum([
                'leftovers as claimed_servings' => fn ($query) => $query
                    ->where('kind', MealEntryKind::Leftover->value)
                    ->when($exceptEntryId !== null, fn ($leftover) => $leftover->whereKeyNot($exceptEntryId)),
            ], 'servings')
            ->orderByDesc('plan_date')
            ->orderByRaw("CASE meal_type WHEN 'breakfast' THEN 1 WHEN 'lunch' THEN 2 ELSE 3 END")
            ->get();
    }
}
