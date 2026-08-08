<?php

declare(strict_types=1);

use App\Models\ChannelIdentity;
use App\Models\User;
use App\Services\Capture\ChannelIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression guard. The one-time backfill only covers users that existed when the
 * migration ran; without this link every account created afterwards is invisible to
 * the capture channel, and the bot answers "you are not linked" to a user who is.
 */
it('deja al usuario recien registrado resoluble por el canal', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
        'whatsapp_phone' => '+51 999 888 777',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]);

    $response->assertOk();

    $user = User::where('email', 'ada@example.test')->sole();

    expect(app(ChannelIdentityResolver::class)->resolve('whatsapp', '51999888777')?->id)
        ->toBe($user->id);
});

it('registra sin identidad de canal cuando no se cargo telefono', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.test',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ]);

    $response->assertOk();

    expect(ChannelIdentity::count())->toBe(0);
});
