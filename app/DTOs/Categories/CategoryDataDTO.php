<?php

declare(strict_types=1);

namespace App\DTOs\Categories;

use App\Enums\BudgetPeriod;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;

final class CategoryDataDTO
{
    /**
     * `budget_period` trails the other fields only because PHP forbids a
     * defaulted parameter before a required one; it belongs conceptually beside
     * `monthly_budget`, whose unit it defines. Every call site uses named
     * arguments, so the position carries no meaning.
     *
     * It is nullable rather than defaulted to 'monthly' on purpose. The request
     * rule is `sometimes`, so an absent field is indistinguishable from an
     * explicit 'monthly' once it is coerced here — and on an update that would
     * silently reset a yearly envelope to monthly every time a client that
     * predates the column saves a category. Null means "not specified":
     * StoreCategoryAction reads it as 'monthly' (a new row needs a value),
     * UpdateCategoryAction omits the column entirely (an existing row keeps its
     * own).
     *
     * `parent_id` needs the same distinction and cannot borrow the same trick,
     * because null is a legitimate value there — it means "a root category", not
     * "unspecified". So the intent travels in `$reparents`: false leaves the
     * column alone, true writes `$parent_id` whatever it is. Without it, an update
     * that never mentioned a parent read as `parent_id = null` and quietly promoted
     * every subcategory to a root one, on every save, from a form that has no
     * parent field at all.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly float $monthly_budget,
        public readonly int $user_id,
        public readonly ?int $parent_id = null,
        public readonly ?int $pareto_classification_id = null,
        public readonly ?BudgetPeriod $budget_period = null,
        public readonly bool $reparents = false,
    ) {
    }

    public static function fromStoreRequest(StoreCategoryRequest $request, int $userId): self
    {
        return new self(
            name: (string) $request->validated('name'),
            type: (string) $request->validated('type'),
            monthly_budget: (float) $request->validated('monthly_budget'),
            user_id: $userId,
            parent_id: $request->validated('parent_id') !== null ? (int) $request->validated('parent_id') : null,
            pareto_classification_id: $request->validated('pareto_classification_id') ? (int) $request->validated('pareto_classification_id') : null,
            budget_period: $request->validated('budget_period') !== null
                ? BudgetPeriod::from((string) $request->validated('budget_period'))
                : null,
            reparents: $request->has('parent_id'),
        );
    }

    public static function fromUpdateRequest(UpdateCategoryRequest $request, int $userId): self
    {
        return new self(
            name: (string) ($request->validated('name') ?? ''),
            type: (string) ($request->validated('type') ?? ''),
            monthly_budget: (float) ($request->validated('monthly_budget') ?? 0),
            user_id: $userId,
            parent_id: $request->validated('parent_id') !== null ? (int) $request->validated('parent_id') : null,
            pareto_classification_id: $request->validated('pareto_classification_id') ? (int) $request->validated('pareto_classification_id') : null,
            budget_period: $request->validated('budget_period') !== null
                ? BudgetPeriod::from((string) $request->validated('budget_period'))
                : null,
            reparents: $request->has('parent_id'),
        );
    }
}
