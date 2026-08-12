<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),

            // Derived from `user_id` rather than declared as a bare
            // `Detail::factory()`. Independently, the two factories each create
            // their own user, so any test that set `user_id` and let the detail
            // default produced a transaction pointing at a stranger's merchant —
            // the exact shape `fk_transactions_detail_id` now rejects, and the
            // exact shape found 29 times in production data.
            //
            // The closure receives the already-resolved attributes, so it sees
            // the caller's `user_id` when one was passed and the id of the user
            // this factory just created when one was not.
            'detail_id' => fn (array $attributes) => Detail::factory()->create([
                'user_id' => $attributes['user_id'],
            ])->id,

            'category_id' => null,
            'amount' => fake()->randomFloat(2, 1, 999),
            'date_operation' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d H:i:s'),
            'type_transaction' => fake()->randomElement(['expense', 'income']),
            'is_manual' => true,
        ];
    }

    /**
     * Estado para transacción de gasto.
     */
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type_transaction' => 'expense',
        ]);
    }

    /**
     * Estado para transacción de ingreso.
     */
    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type_transaction' => 'income',
        ]);
    }

    /**
     * Estado para transacción NO manual (importada).
     */
    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => false,
        ]);
    }

    /**
     * Estado para transacción con categoría asignada.
     */
    public function withCategory(int $categoryId): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $categoryId,
        ]);
    }
}
