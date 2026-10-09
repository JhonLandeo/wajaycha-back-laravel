<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

/**
 * The result of resolving the grocery Category by exact name
 * (design.md D6, D2 — `GroceryBudgetRepositoryContract::findCategoryByExactName()`).
 *
 * Carries only what `ResolveGroceryCategoryAction` needs to write the pin —
 * never the Eloquent `Category` itself (`BoundariesTest.php`: a DTO may not
 * import `App\Models`).
 */
final class GroceryCategoryMatch
{
    public function __construct(
        public readonly int $categoryId,
        public readonly string $categoryName,
    ) {}
}
