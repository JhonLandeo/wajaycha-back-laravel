<?php

declare(strict_types=1);

namespace App\Actions\Shopping;

use App\Models\GroceryBudgetLink;
use App\Repositories\Contracts\GroceryBudgetRepositoryContract;

/**
 * Lazy-resolve-then-pin (design.md D6, spec.md "Lazy-resolve-then-pin
 * lifecycle"). Deliberately callable from a GET — the write it performs is
 * idempotent, converging, and derived entirely from rows the caller already
 * owns; it memoises a resolution rather than mutating anything the user
 * did not already have.
 */
final class ResolveGroceryCategoryAction
{
    public function __construct(
        private readonly GroceryBudgetRepositoryContract $repository,
    ) {}

    /**
     * 1. An existing pin wins outright — the name is NEVER read again once
     *    a link exists.
     * 2. Absent → resolve by the exact configured name.
     * 3. No match → absent, writing nothing. A rename is legitimate and
     *    must not raise.
     * 4. Match → pin it, `resolvedBy: 'auto'`.
     */
    public function execute(int $userId): ?GroceryBudgetLink
    {
        $link = $this->repository->findLink($userId);

        if ($link !== null) {
            return $link;
        }

        $match = $this->repository->findCategoryByExactName(
            $userId,
            (string) config('shopping.grocery_category_name'),
        );

        if ($match === null) {
            return null;
        }

        return $this->repository->pin($userId, $match->categoryId, 'auto');
    }
}
