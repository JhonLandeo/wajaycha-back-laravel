<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Models\PantryItem;
use App\Repositories\Contracts\ShoppingRepositoryContract;

final class UpdatePantryItemAction
{
    public function __construct(
        private readonly ShoppingRepositoryContract $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already validated by UpdatePantryItemRequest.
     */
    public function execute(PantryItem $pantryItem, array $data): PantryItem
    {
        $this->repository->updatePantryItem($pantryItem, [
            'product_id' => (int) $data['product_id'],
            'quantity' => (float) $data['quantity'],
            'unit' => (string) $data['unit'],
            'acquisition_source' => (string) $data['acquisition_source'],
            'acquired_on' => $data['acquired_on'] ?? $pantryItem->acquired_on,
            'expires_on' => array_key_exists('expires_on', $data) ? $data['expires_on'] : $pantryItem->expires_on,
            'note' => array_key_exists('note', $data) ? $data['note'] : $pantryItem->note,
        ]);

        return $pantryItem->fresh();
    }
}
