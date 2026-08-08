<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChannelLinkToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelLinkToken>
 */
class ChannelLinkTokenFactory extends Factory
{
    protected $model = ChannelLinkToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => now()->addMinutes(15),
            'redeemed_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function redeemed(): static
    {
        return $this->state(fn () => ['redeemed_at' => now()]);
    }
}
