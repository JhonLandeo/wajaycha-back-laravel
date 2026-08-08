<?php

declare(strict_types=1);

use App\Models\ChannelIdentity;
use App\Models\User;
use App\Services\Capture\ChannelIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\LazyCollection;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = app(ChannelIdentityResolver::class);
});

/**
 * design.md D4: `$channels` is an ordered preference list the caller passes,
 * never defaulted or re-ordered inside the resolver. `channel_identities` is
 * unique on (channel, external_id), not on user_id, so a user may legitimately
 * hold both a WhatsApp and a Telegram identity — preference must be deliberate.
 */
it('prefers telegram over whatsapp for a dual-identity user, deliberately not by insertion order', function () {
    $user = User::factory()->create();

    // WhatsApp inserted FIRST on purpose: if the resolver picked "the first row"
    // or "the lowest id", this would return whatsapp and the test would fail —
    // it must be the ordered preference list that decides, nothing else.
    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);
    $telegram = ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => '123456789',
    ]);

    $preferred = $this->resolver->preferredIdentityFor($user->id, ['telegram', 'whatsapp']);

    expect($preferred?->id)->toBe($telegram->id)
        ->and($preferred?->channel)->toBe('telegram');
});

it('follows the SAME dual-identity user to whatsapp when the preference order is reversed', function () {
    // Triangulation: same fixtures as above, only the preference order changes.
    // Proves the choice is driven by $channels, not a hardcoded telegram-first rule.
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);
    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => '123456789',
    ]);

    $preferred = $this->resolver->preferredIdentityFor($user->id, ['whatsapp', 'telegram']);

    expect($preferred?->channel)->toBe('whatsapp');
});

it('returns null when the user holds none of the requested channels', function () {
    $user = User::factory()->create();
    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);

    expect($this->resolver->preferredIdentityFor($user->id, ['telegram']))->toBeNull();
});

it('excludes a WhatsApp-only user from userIdsReachableOn telegram — never coached (ADR-0007)', function () {
    $telegramUser = User::factory()->create();
    $whatsappOnlyUser = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $telegramUser->id,
        'channel' => 'telegram',
        'external_id' => '111111',
    ]);
    ChannelIdentity::factory()->create([
        'user_id' => $whatsappOnlyUser->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);

    $reachable = $this->resolver->userIdsReachableOn(['telegram'])->all();

    expect($reachable)->toContain($telegramUser->id)
        ->and($reachable)->not->toContain($whatsappOnlyUser->id);
});

it('includes a dual-identity user exactly once on userIdsReachableOn telegram', function () {
    $dualUser = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $dualUser->id,
        'channel' => 'whatsapp',
        'external_id' => '51999888777',
    ]);
    ChannelIdentity::factory()->create([
        'user_id' => $dualUser->id,
        'channel' => 'telegram',
        'external_id' => '123456789',
    ]);

    // Se piden AMBOS canales a proposito: con uno solo el usuario tiene una sola
    // fila que matchea y el distinct() nunca entra en juego, asi que el test pasaria
    // igual con el distinct borrado. Con los dos, la dedup es lo unico que evita
    // que aparezca repetido.
    $reachable = $this->resolver->userIdsReachableOn(['telegram', 'whatsapp'])->all();

    expect(array_count_values($reachable)[$dualUser->id] ?? 0)->toBe(1);
});

it('sigue devolviendo al usuario dual una sola vez cuando solo se pide telegram', function () {
    $dualUser = User::factory()->create();

    ChannelIdentity::factory()->create(['user_id' => $dualUser->id, 'channel' => 'whatsapp', 'external_id' => '51999888777']);
    ChannelIdentity::factory()->create(['user_id' => $dualUser->id, 'channel' => 'telegram', 'external_id' => '123456789']);

    $reachable = $this->resolver->userIdsReachableOn(['telegram'])->all();

    expect(array_count_values($reachable)[$dualUser->id] ?? 0)->toBe(1);
});

it('returns a LazyCollection so the sweep never loads the whole table eagerly', function () {
    User::factory()->create();

    expect($this->resolver->userIdsReachableOn(['telegram']))->toBeInstanceOf(LazyCollection::class);
});
