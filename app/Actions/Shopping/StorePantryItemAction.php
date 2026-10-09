<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Models\PantryItem;
use App\Repositories\Contracts\ShoppingRepositoryContract;

final class StorePantryItemAction
{
    public function __construct(
        private readonly ShoppingRepositoryContract $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already validated by StorePantryItemRequest.
     */
    public function execute(int $userId, array $data): PantryItem
    {
        return $this->repository->createPantryItem([
            'user_id' => $userId,
            'product_id' => (int) $data['product_id'],
            'quantity' => (float) $data['quantity'],
            'unit' => (string) $data['unit'],
            'acquisition_source' => (string) $data['acquisition_source'],
            // Omitted rather than defaulted here when absent: the migration's
            // own `CURRENT_DATE` default exists for direct inserts, but the
            // app sets it explicitly whenever the caller sends one, and
            // `now()` respects `config('app.timezone')` (America/Lima) the
            // same way the rest of the request lifecycle does.
            'acquired_on' => $data['acquired_on'] ?? now()->toDateString(),
            'expires_on' => $data['expires_on'] ?? null,
            'note' => $data['note'] ?? null,
        ]);
    }
}
