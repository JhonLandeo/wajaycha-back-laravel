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
            ];

            // Same reason as `budget_period` below, different mechanism: null is a
            // real parent value ("root"), so absence has to be signalled separately.
            // The category form sends no parent field, so writing `$dto->parent_id`
            // unconditionally turned every subcategory into a root one on save.
            if ($dto->reparents) {
                $data['parent_id'] = $dto->parent_id;
            }

            // Omitted, never defaulted: a client that does not know the column
            // exists must not reset a yearly envelope back to monthly by saving
            // an unrelated field.
            if ($dto->budget_period !== null) {
                $data['budget_period'] = $dto->budget_period->value;
            }

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
