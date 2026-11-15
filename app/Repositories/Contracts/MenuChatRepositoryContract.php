<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\MenuChatMessage;

interface MenuChatRepositoryContract
{
    /**
     * The most recent $limit turns for one user and one week, returned oldest
     * first so the caller can map them straight into an AI `contents` array.
     *
     * @return MenuChatMessage[]
     */
    public function historyFor(int $userId, string $weekStart, int $limit = 20): array;

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function append(
        int $userId,
        string $weekStart,
        string $role,
        string $content,
        ?array $payload = null,
    ): MenuChatMessage;
}
