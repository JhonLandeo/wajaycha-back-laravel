<?php

declare(strict_types=1);

namespace App\Actions\Categories;

use App\DTOs\Categories\CategoryDataDTO;
use App\Models\Category;
use App\Repositories\Contracts\CategoryRepositoryContract;
use Illuminate\Support\Facades\DB;

final class StoreCategoryAction
{
    public function __construct(
        private readonly CategoryRepositoryContract $repository
    ) {
    }

    public function execute(CategoryDataDTO $dto): Category
    {
        return DB::transaction(function () use ($dto) {
            $data = [
                'name' => $dto->name,
                'type' => $dto->type,
                'monthly_budget' => $dto->monthly_budget,
                'user_id' => $dto->user_id,
                'parent_id' => $dto->parent_id,
            ];

            $category = $this->repository->create($data);

            if ($dto->pareto_classification_id) {
                DB::table('category_pareto_assignments')->insert([
                    'category_id' => $category->id,
                    'pareto_classification_id' => $dto->pareto_classification_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $category;
        });
    }
}
