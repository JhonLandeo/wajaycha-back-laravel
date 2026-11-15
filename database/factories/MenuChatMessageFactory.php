<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MenuChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuChatMessage>
 */
class MenuChatMessageFactory extends Factory
{
    protected $model = MenuChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week_start' => now()->startOfWeek()->toDateString(),
            'role' => 'user',
            'content' => fake()->sentence(),
            'payload' => null,
        ];
    }
}
