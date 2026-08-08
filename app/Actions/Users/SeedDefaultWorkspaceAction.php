<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\Category;
use App\Models\ParetoClassification;
use App\Models\Tag;
use App\Models\User;
use App\Repositories\Contracts\CategoryRepositoryContract;
use Illuminate\Support\Facades\DB;

/**
 * Gives a new account the Pareto classifications, category tree and tags it starts with.
 *
 * This was a hundred and sixty three line method inside `UserObserver::created()`,
 * where the shape of a new account, the loop that writes it and raw pivot inserts all
 * shared one body. The blueprint now lives in `config/onboarding.php` — it is data, and
 * changing a category name should not mean reading control flow.
 *
 * **The transfer rule, which existed nowhere but the seed.** A transfer carries a
 * Pareto band when the money leaves: paying a credit card or a loan's principal is a
 * fixed obligation however it is typed, and saving competes for the same money as
 * everything else. It carries none when the money merely moves — between your own
 * accounts, out on loan, or fronted against a reimbursement. Counting those would
 * inflate every reading the product exists to give. In the config that is `band => null`.
 */
class SeedDefaultWorkspaceAction
{
    public function __construct(
        private readonly CategoryRepositoryContract $categories,
    ) {}

    public function execute(User $user): void
    {
        // All of it or none of it. Previously each insert stood alone, so a failure
        // partway left an account that looks complete and reports nothing — no
        // classifications to band against, or a tree with half its branches.
        DB::transaction(function () use ($user): void {
            $bands = $this->seedClassifications($user);
            $this->seedCategories($user, $bands);
            $this->seedTags($user);
        });
    }

    /**
     * @return array<string, int> band name => classification id
     */
    private function seedClassifications(User $user): array
    {
        $now = now();

        /** @var array<int, array{name: string, percentage: int}> $blueprint */
        $blueprint = config('onboarding.pareto_classifications', []);

        ParetoClassification::insert(array_map(
            fn (array $row): array => [
                'name' => $row['name'],
                'percentage' => $row['percentage'],
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $blueprint
        ));

        /** @var array<string, int> */
        return ParetoClassification::query()
            ->where('user_id', $user->id)
            ->pluck('id', 'name')
            ->toArray();
    }

    /**
     * @param  array<string, int>  $bands
     */
    private function seedCategories(User $user, array $bands): void
    {
        /** @var array<int, array{name: string, type: string, band?: ?string, children?: array<int, array{name: string, type: string, band?: ?string}>}> $groups */
        $groups = config('onboarding.categories', []);

        foreach ($groups as $group) {
            $parent = $this->createCategory($user, $group['name'], $group['type'], null);
            $this->band($parent->id, $group['band'] ?? null, $bands);

            foreach ($group['children'] ?? [] as $child) {
                $created = $this->createCategory($user, $child['name'], $child['type'], $parent->id);
                $this->band($created->id, $child['band'] ?? null, $bands);
            }
        }
    }

    private function createCategory(User $user, string $name, string $type, ?int $parentId): Category
    {
        return Category::create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
            'parent_id' => $parentId,
        ]);
    }

    /**
     * @param  array<string, int>  $bands
     */
    private function band(int $categoryId, ?string $bandName, array $bands): void
    {
        if ($bandName === null || ! isset($bands[$bandName])) {
            return;
        }

        $this->categories->assignParetoClassification($categoryId, $bands[$bandName]);
    }

    private function seedTags(User $user): void
    {
        if ($user->tags()->count() > 0) {
            return;
        }

        $now = now();

        /** @var array<int, string> $names */
        $names = config('onboarding.tags', []);

        Tag::insert(array_map(
            fn (string $name): array => [
                'user_id' => $user->id,
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $names
        ));
    }
}
