<?php

declare(strict_types=1);

namespace App\Actions\Categories;

use App\DTOs\Categories\CategoryDataDTO;
use App\Enums\BudgetPeriod;
use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryContract;
use Illuminate\Support\Facades\DB;

final class StoreCategoryAction
{
    public function __construct(
        private readonly CategoryRepositoryContract $repository
    ) {}

    public function execute(CategoryDataDTO $dto): Category
    {
        return DB::transaction(function () use ($dto) {
            $data = [
                'name' => $dto->name,
                'type' => $dto->type,
                'monthly_budget' => $dto->monthly_budget,
                // A new row needs a concrete value, and 'monthly' is what every
                // caller that omits the field already meant.
                'budget_period' => ($dto->budget_period ?? BudgetPeriod::MONTHLY)->value,
                'user_id' => $dto->user_id,
                'parent_id' => $dto->parent_id,
            ];

            $category = $this->repository->create($data);

            if ($dto->pareto_classification_id) {
                $this->repository->assignParetoClassification(
                    $category->id,
                    $dto->pareto_classification_id
                );
            }

            return $category;
        });
    }
}
