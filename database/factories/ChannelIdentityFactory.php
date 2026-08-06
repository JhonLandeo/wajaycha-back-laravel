<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChannelIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelIdentity>
 */
class ChannelIdentityFactory extends Factory
{
    protected $model = ChannelIdentity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel' => 'whatsapp',
            'external_id' => (string) fake()->unique()->numerify('51#########'),
            'linked_at' => now(),
            'legacy_whatsapp_phone' => null,
        ];
    }
}
