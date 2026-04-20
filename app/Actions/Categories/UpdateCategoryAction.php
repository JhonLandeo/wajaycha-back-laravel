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
    ) {
    }

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
                DB::table('category_pareto_assignments')->updateOrInsert(
                    ['category_id' => $category->id],
                    [
                        'pareto_classification_id' => $dto->pareto_classification_id,
                        'updated_at' => now(),
                    ]
                );
            } else {
                DB::table('category_pareto_assignments')
                    ->where('category_id', $category->id)
                    ->delete();
            }

            return $category->fresh();
        });
    }
}
