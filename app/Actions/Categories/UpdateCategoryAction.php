<?php

declare(strict_types=1);

namespace App\Actions\Categories;

use App\DTOs\Categories\CategoryDataDTO;
use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryContract;
use Illuminate\Support\Facades\DB;

final class UpdateCategoryAction
{
    public function __construct(
        private readonly CategoryRepositoryContract $repository
    ) {}

    public function execute(Category $category, CategoryDataDTO $dto): Category
    {
        return DB::transaction(function () use ($category, $dto) {
            $data = [
                'name' => $dto->name,
                'type' => $dto->type,
                'monthly_budget' => $dto->monthly_budget,
                'parent_id' => $dto->parent_id,
            ];

            $this->repository->update($category, $data);

            if ($dto->pareto_classification_id) {
                $this->repository->assignParetoClassification(
                    $category->id,
                    $dto->pareto_classification_id
                );
            } else {
                $this->repository->clearParetoClassification($category->id);
            }

            return $category->fresh();
        });
    }
}
