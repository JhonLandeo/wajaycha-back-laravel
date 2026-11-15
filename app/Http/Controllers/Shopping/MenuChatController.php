<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shopping;

use App\Actions\Shopping\ApplyMenuChatOperationsAction;
use App\Actions\Shopping\ChatAdjustMenuAction;
use App\DTOs\Shopping\PlanningWeek;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\ApplyMenuChatOperationsRequest;
use App\Http\Requests\Shopping\SendMenuChatMessageRequest;
use App\Models\MenuChatMessage;
use App\Repositories\Contracts\MenuChatRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class MenuChatController extends Controller
{
    public function __construct(
        private readonly MenuChatRepositoryContract $repository,
        private readonly ChatAdjustMenuAction $chat,
        private readonly ApplyMenuChatOperationsAction $applyOperations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'week_start' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $week = $this->week($validated['week_start'] ?? null);
        $messages = $this->repository->historyFor(
            (int) Auth::id(),
            $week->startsOn->toDateString(),
            50,
        );

        return response()->json([
            'data' => [
                'week_start' => $week->startsOn->toDateString(),
                'messages' => array_map(fn (MenuChatMessage $message): array => $this->payload($message), $messages),
            ],
        ]);
    }

    public function store(SendMenuChatMessageRequest $request): JsonResponse
    {
        $week = $this->week((string) $request->validated('week_start'));

        $result = $this->chat->execute(
            (int) Auth::id(),
            $week->startsOn->toDateString(),
            (string) $request->validated('message'),
        );

        return response()->json(['data' => $result]);
    }

    public function apply(ApplyMenuChatOperationsRequest $request): JsonResponse
    {
        $week = $this->week((string) $request->validated('week_start'));

        $operations = $this->applyOperations->execute(
            (int) Auth::id(),
            $week->startsOn->toDateString(),
            (array) $request->validated('operations'),
        );

        $this->repository->append(
            (int) Auth::id(),
            $week->startsOn->toDateString(),
            'assistant',
            'Cambios aplicados al menú.',
            ['applied' => $operations],
        );

        return response()->json(['data' => ['applied' => $operations]]);
    }

    private function week(?string $weekStart): PlanningWeek
    {
        $asOf = CarbonImmutable::now();
        $start = $weekStart !== null
            ? CarbonImmutable::parse($weekStart, 'America/Lima')
            : $asOf;

        return PlanningWeek::forWeekStarting($start, $asOf);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MenuChatMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'operations' => $message->payload['operations'] ?? $message->payload['applied'] ?? [],
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
