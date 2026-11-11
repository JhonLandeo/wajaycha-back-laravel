<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\BuildMenuShoppingListAction;
use App\Actions\Shopping\BuildMenuSpendingAction;
use App\Actions\Shopping\SaveMealPlanEntryAction;
use App\Actions\Shopping\SuggestMenuAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\StoreMealPlanEntryRequest;
use App\Http\Requests\Shopping\UpdateMealEntryStatusRequest;
use App\Http\Requests\Shopping\UpdateMealPlanSettingsRequest;
use App\Models\MealPlanEntry;
use App\Models\MealPlanSettings;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class MealPlanController extends Controller
{
    public function __construct(
        private readonly MealPlanRepositoryContract $repository,
        private readonly SaveMealPlanEntryAction $saveEntry,
        private readonly BuildMenuShoppingListAction $buildShoppingList,
        private readonly BuildMenuSpendingAction $buildSpending,
        private readonly SuggestMenuAction $suggestMenu,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        $week = $this->weekFrom($request);

        return response()->json(['data' => $this->menuPayload($userId, $week)]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        $week = $this->weekFrom($request);
        $source = $this->suggestMenu->execute($userId, $week->startsOn->toDateString());

        return response()->json([
            'data' => $this->menuPayload($userId, $week),
            'source' => $source,
        ]);
    }

    public function updateSettings(UpdateMealPlanSettingsRequest $request): JsonResponse
    {
        $userId = (int) Auth::id();
        $settings = $this->repository->settingsFor($userId);
        $updated = $this->repository->updateSettings($settings, [
            'household_size' => (int) $request->validated('household_size'),
            'meal_types' => array_values(array_unique($request->validated('meal_types'))),
            'plan_weekdays' => array_values(array_unique(array_map('intval', $request->validated('plan_weekdays')))),
            'excluded_terms' => array_values(array_unique($request->validated('excluded_terms', []))),
            'max_prep_minutes' => $request->validated('max_prep_minutes'),
        ]);

        return response()->json(['data' => $this->settingsPayload($updated)]);
    }

    public function saveEntry(StoreMealPlanEntryRequest $request): JsonResponse
    {
        $entry = $this->saveEntry->execute((int) Auth::id(), $request->validated());

        return response()->json(['data' => $this->entryPayload($entry)]);
    }

    public function updateStatus(UpdateMealEntryStatusRequest $request, int $entry): JsonResponse
    {
        $model = $this->repository->findEntry($entry, (int) Auth::id());

        if ($model === null) {
            abort(404);
        }

        $updated = $this->repository->updateStatus($model, \App\Enums\MealEntryStatus::from((string) $request->validated('status')));

        return response()->json(['data' => $this->entryPayload($updated)]);
    }

    public function destroyEntry(int $entry): JsonResponse
    {
        $model = $this->repository->findEntry($entry, (int) Auth::id());

        if ($model === null) {
            abort(404);
        }

        $this->repository->deleteEntry($model);

        return response()->json(['status' => 'deleted']);
    }

    public function shoppingList(Request $request): JsonResponse
    {
        $week = $this->weekFrom($request);

        return response()->json([
            'data' => $this->buildShoppingList->execute((int) Auth::id(), $week->startsOn->toDateString()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function menuPayload(int $userId, \App\DTOs\Shopping\PlanningWeek $week): array
    {
        $settings = $this->repository->settingsFor($userId);
        $entries = $this->repository->entriesForWeek($userId, $week->startsOn, $week->endsOn);

        return [
            'week' => $this->weekPayload($week),
            'settings' => $this->settingsPayload($settings),
            'entries' => $entries->map(fn (MealPlanEntry $entry): array => $this->entryPayload($entry))->values()->all(),
        ];
    }

    public function spending(Request $request): JsonResponse
    {
        $week = $this->weekFrom($request);

        return response()->json([
            'data' => $this->buildSpending->execute((int) Auth::id(), $week->startsOn->toDateString()),
        ]);
    }

    private function weekFrom(Request $request): \App\DTOs\Shopping\PlanningWeek
    {
        $validated = $request->validate([
            'week_start' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $asOf = CarbonImmutable::now();
        $start = isset($validated['week_start'])
            ? CarbonImmutable::parse((string) $validated['week_start'], 'America/Lima')
            : $asOf;

        return \App\DTOs\Shopping\PlanningWeek::forWeekStarting($start, $asOf);
    }

    /**
     * @return array<string, string>
     */
    private function weekPayload(\App\DTOs\Shopping\PlanningWeek $week): array
    {
        return [
            'starts_on' => $week->startsOn->toDateString(),
            'ends_on' => $week->endsOn->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(MealPlanSettings $settings): array
    {
        return [
            'household_size' => (int) $settings->household_size,
            'meal_types' => $settings->meal_types,
            'plan_weekdays' => $settings->plan_weekdays,
            'excluded_terms' => $settings->excluded_terms ?? [],
            'max_prep_minutes' => $settings->max_prep_minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryPayload(MealPlanEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'plan_date' => $entry->plan_date->toDateString(),
            'meal_type' => $entry->meal_type->value,
            'meal_type_label' => $entry->meal_type->label(),
            'kind' => $entry->kind->value,
            'kind_label' => $entry->kind->label(),
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
            'recipe_id' => $entry->recipe_id,
            'recipe' => $entry->recipe === null ? null : [
                'id' => (int) $entry->recipe->id,
                'name' => $entry->recipe->name,
                'base_servings' => (int) $entry->recipe->base_servings,
                'prep_minutes' => $entry->recipe->prep_minutes,
                'meal_types' => $entry->recipe->meal_types,
            ],
            'servings' => (int) $entry->servings,
            'cook_servings' => (int) $entry->cook_servings,
            'leftover_of_entry_id' => $entry->leftover_of_entry_id,
            'leftover_from' => $entry->leftoverOf === null ? null : [
                'id' => (int) $entry->leftoverOf->id,
                'plan_date' => $entry->leftoverOf->plan_date->toDateString(),
                'meal_type' => $entry->leftoverOf->meal_type->value,
                'recipe_name' => $entry->leftoverOf->recipe?->name,
            ],
            'notes' => $entry->notes,
        ];
    }
}
