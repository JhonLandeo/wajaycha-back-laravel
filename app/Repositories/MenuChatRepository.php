<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\MenuChatMessage;
use App\Repositories\Contracts\MenuChatRepositoryContract;

final class MenuChatRepository implements MenuChatRepositoryContract
{
    public function historyFor(int $userId, string $weekStart, int $limit = 20): array
    {
        return MenuChatMessage::query()
            ->where('user_id', $userId)
            ->whereDate('week_start', $weekStart)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->all();
    }

    public function append(
        int $userId,
        string $weekStart,
        string $role,
        string $content,
        ?array $payload = null,
    ): MenuChatMessage {
        /** @var MenuChatMessage */
        return MenuChatMessage::query()->create([
            'user_id' => $userId,
            'week_start' => $weekStart,
            'role' => $role,
            'content' => $content,
            'payload' => $payload,
        ]);
    }
}
