<?php

declare(strict_types=1);

use App\Models\ChannelIdentity;
use App\Models\User;
use App\Services\Capture\ChannelIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = app(ChannelIdentityResolver::class);
});

it('resuelve una cuenta de canal a su usuario', function () {
    $user = User::factory()->create();
    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);

    expect($this->resolver->resolve('whatsapp', '51999888777')?->id)->toBe($user->id);
});

it('no resuelve una cuenta desconocida', function () {
    User::factory()->create();

    expect($this->resolver->resolve('whatsapp', '51000000000'))->toBeNull();
});

it('trata el mismo external id en dos canales como dos identidades distintas', function () {
    $whatsappUser = User::factory()->create();
    $telegramUser = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $whatsappUser->id,
        'channel' => 'whatsapp',
        'external_id' => '123456',
    ]);
    ChannelIdentity::factory()->create([
        'user_id' => $telegramUser->id,
        'channel' => 'telegram',
        'external_id' => '123456',
    ]);

    expect($this->resolver->resolve('whatsapp', '123456')?->id)->toBe($whatsappUser->id)
        ->and($this->resolver->resolve('telegram', '123456')?->id)->toBe($telegramUser->id);
});

it('impide que una cuenta de canal quede ligada a dos usuarios', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $first->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);

    expect(fn () => ChannelIdentity::factory()->create([
        'user_id' => $second->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]))->toThrow(Illuminate\Database\QueryException::class);
});
