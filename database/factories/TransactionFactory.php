<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
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
            'user_id'          => User::factory(),
            'detail_id'        => Detail::factory(),
            'category_id'      => null,
            'amount'           => fake()->randomFloat(2, 1, 999),
            'date_operation'   => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d H:i:s'),
            'type_transaction' => fake()->randomElement(['expense', 'income']),
            'is_manual'        => true,
            'yape_id'          => null,
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
