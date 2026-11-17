<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\ApplyMenuSuggestionAction;
use App\Actions\Shopping\BuildMenuShoppingListAction;
use App\Actions\Shopping\BuildMenuSpendingAction;
use App\Actions\Shopping\ChatAdjustMenuPreviewAction;
use App\Actions\Shopping\SaveMealPlanEntryAction;
use App\Actions\Shopping\SuggestMealAlternativeAction;
use App\Actions\Shopping\SuggestMenuAction;
use App\Actions\Shopping\SwapMenuDaysAction;
use App\Actions\Shopping\SwapMenuEntriesAction;
use App\Actions\Shopping\UpdateMealEntryStatusAction;
use App\Enums\MealEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\ApplyMenuSuggestionRequest;
use App\Http\Requests\Shopping\SendPreviewMenuChatRequest;
use App\Http\Requests\Shopping\StoreMealPlanEntryRequest;
use App\Http\Requests\Shopping\SuggestMealAlternativeRequest;
use App\Http\Requests\Shopping\SwapMenuDaysRequest;
use App\Http\Requests\Shopping\SwapMenuEntriesRequest;
use App\Http\Requests\Shopping\UpdateMealEntryStatusRequest;
use App\Http\Requests\Shopping\UpdateMealPlanSettingsRequest;
use App\Models\MealPlanEntry;
use App\Repositories\Contracts\MealPlanRepositoryContract;
use App\Services\Shopping\MealPlanPresenter;
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
        private readonly ApplyMenuSuggestionAction $applySuggestion,
        private readonly UpdateMealEntryStatusAction $updateStatusAction,
        private readonly MealPlanPresenter $presenter,
        private readonly SuggestMealAlternativeAction $alternativeSuggestion,
        private readonly ChatAdjustMenuPreviewAction $previewChatAction,
        private readonly SwapMenuDaysAction $swapMenuDaysAction,
        private readonly SwapMenuEntriesAction $swapMenuEntriesAction,
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

    public function previewSuggestion(Request $request): JsonResponse
    {
        $userId = (int) Auth::id();
        $week = $this->weekFrom($request);
        $preview = $this->suggestMenu->preview($userId, $week->startsOn->toDateString());
        $settings = $this->repository->settingsFor($userId);

        return response()->json([
            'source' => $preview['source'],
            'data' => [
                'week' => $this->presenter->week($preview['week']),
                'settings' => $this->presenter->settings($settings),
                'entries' => $preview['entries']
                    ->map(fn (MealPlanEntry $entry): array => $this->presenter->entry($entry))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function alternativeSuggestion(SuggestMealAlternativeRequest $request): JsonResponse
    {
        $week = \App\DTOs\Shopping\PlanningWeek::forWeekStarting(
            CarbonImmutable::parse((string) $request->validated('week_start'), 'America/Lima'),
            CarbonImmutable::now(),
        );

        $entry = $this->alternativeSuggestion->execute(
            (int) Auth::id(),
            $week->startsOn->toDateString(),
            (string) $request->validated('date'),
            (string) $request->validated('meal'),
            (array) $request->validated('entries'),
        );

        if ($entry === null) {
            return response()->json([
                'message' => 'No encontramos otra opción para esa comida.',
            ], 422);
        }

        return response()->json(['data' => $entry]);
    }

    public function previewChat(SendPreviewMenuChatRequest $request): JsonResponse
    {
        $week = \App\DTOs\Shopping\PlanningWeek::forWeekStarting(
            CarbonImmutable::parse((string) $request->validated('week_start'), 'America/Lima'),
            CarbonImmutable::now(),
        );

        $result = $this->previewChatAction->execute(
            (int) Auth::id(),
            $week->startsOn->toDateString(),
            (string) $request->validated('message'),
            (array) $request->validated('entries'),
        );

        return response()->json(['data' => $result]);
    }

    public function applySuggestion(ApplyMenuSuggestionRequest $request): JsonResponse
    {
        $week = $this->weekFrom($request);

        $this->applySuggestion->execute(
            (int) Auth::id(),
            $week->startsOn->toDateString(),
            (array) $request->validated('entries'),
        );

        return response()->json(['status' => 'applied']);
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
            'menu_strategy' => $request->validated('menu_strategy', $settings->menu_strategy->value),
            'available_equipment' => $request->validated('available_equipment', $settings->available_equipment),
            'diet_profile' => $request->validated('diet_profile', $settings->diet_profile->value),
            'new_recipes_per_week' => $request->validated('new_recipes_per_week', $settings->new_recipes_per_week),
            'daily_calorie_target' => $request->validated('daily_calorie_target', $settings->daily_calorie_target),
            'dinner_carb_limit_g' => $request->validated('dinner_carb_limit_g', $settings->dinner_carb_limit_g),
            'dinner_max_prep_minutes' => $request->validated('dinner_max_prep_minutes', $settings->dinner_max_prep_minutes),
            'dinner_calorie_limit' => $request->validated('dinner_calorie_limit', $settings->dinner_calorie_limit),
            'consider_pantry_in_ai_recipes' => $request->validated('consider_pantry_in_ai_recipes', $settings->consider_pantry_in_ai_recipes),
        ]);

        return response()->json(['data' => $this->presenter->settings($updated)]);
    }

    public function saveEntry(StoreMealPlanEntryRequest $request): JsonResponse
    {
        $entry = $this->saveEntry->execute((int) Auth::id(), $request->validated());

        return response()->json(['data' => $this->presenter->entry($entry)]);
    }

    public function updateStatus(UpdateMealEntryStatusRequest $request, int $entry): JsonResponse
    {
        $model = $this->repository->findEntry($entry, (int) Auth::id());

        if ($model === null) {
            abort(404);
        }

        $updated = $this->updateStatusAction->execute(
            $model,
            MealEntryStatus::from((string) $request->validated('status')),
        );

        return response()->json(['data' => $this->presenter->entry($updated)]);
    }

    public function swapDays(SwapMenuDaysRequest $request): JsonResponse
    {
        $this->swapMenuDaysAction->execute(
            (int) Auth::id(),
            (string) $request->validated('week_start'),
            (string) $request->validated('date_a'),
            (string) $request->validated('date_b'),
        );

        return response()->json(['status' => 'swapped']);
    }

    public function swapEntries(SwapMenuEntriesRequest $request): JsonResponse
    {
        $this->swapMenuEntriesAction->execute(
            (int) Auth::id(),
            (string) $request->validated('week_start'),
            (string) $request->validated('date_a'),
            (string) $request->validated('date_b'),
            \App\Enums\MealType::from((string) $request->validated('meal_type')),
        );

        return response()->json(['status' => 'swapped']);
    }

    public function clearWeek(Request $request): JsonResponse
    {
        $week = $this->weekFrom($request);
        $this->repository->clearWeek((int) Auth::id(), $week->startsOn, $week->endsOn);

        return response()->json(['status' => 'cleared']);
    }

    public function clearDay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $this->repository->clearDay(
            (int) Auth::id(),
            CarbonImmutable::parse((string) $validated['date'], 'America/Lima'),
        );

        return response()->json(['status' => 'cleared']);
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
            'week' => $this->presenter->week($week),
            'settings' => $this->presenter->settings($settings),
            'entries' => $entries->map(fn (MealPlanEntry $entry): array => $this->presenter->entry($entry))->values()->all(),
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
}
