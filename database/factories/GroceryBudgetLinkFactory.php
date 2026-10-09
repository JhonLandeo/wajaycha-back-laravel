<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\GroceryBudgetLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroceryBudgetLink>
 */
class GroceryBudgetLinkFactory extends Factory
{
    protected $model = GroceryBudgetLink::class;

    /**
     * `user_id` and `category_id` are independent factories by default, same
     * as CategoryFactory. The composite FK requires both to belong to the
     * same user, so a test that needs a real pin must set both explicitly —
     * this default is only valid where the composite FK is not exercised.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'resolved_by' => 'auto',
            'linked_at' => now(),
        ];
    }
}
