<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ChannelIdentity;
use App\Models\PaydayPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface as Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared setup for the payday plan feature tests: a user with an active plan
 * and a linked Telegram chat, the kill switch on, and Telegram faked.
 */
final class PaydayFixtures
{
    public const CHAT = '555000111';

    public static function enable(): void
    {
        config()->set('payday.enabled', true);
        config()->set('services.telegram.bot_token', 'BOT-TOKEN');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /**
     * Replaces Telegram's fake. `Http::fake()` called twice keeps the FIRST stub
     * that matches, so changing the behaviour mid-test needs a fresh factory.
     */
    public static function fakeWith(callable|Response $respond): void
    {
        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => $respond]);
    }

    public static function accept(): void
    {
        self::fakeWith(Http::response(['ok' => true]));
    }

    public static function reject(int $status = 400): void
    {
        self::fakeWith(Http::response(['ok' => false], $status));
    }

    /** @param array<string, mixed> $plan */
    public static function userWithPlan(array $plan = [], ?string $chat = self::CHAT): User
    {
        $user = User::factory()->create();
        PaydayPlan::factory()->create(['user_id' => $user->id, ...$plan]);

        if ($chat !== null) {
            self::link($user, $chat);
        }

        return $user;
    }

    public static function link(User $user, string $chat = self::CHAT, string $channel = 'telegram'): void
    {
        ChannelIdentity::factory()->create([
            'user_id' => $user->id,
            'channel' => $channel,
            'external_id' => $chat,
        ]);
    }

    public static function at(string $when): CarbonImmutable
    {
        return CarbonImmutable::parse($when, 'America/Lima');
    }

    /**
     * The sendMessage requests Telegram accepted. A rejected request is recorded
     * by the fake too, but it never reached the user, so it is not "sent".
     *
     * @return array<int, Request>
     */
    public static function sent(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/sendMessage')
                && $pair[1] !== null
                && $pair[1]->successful())
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public static function sentTexts(): array
    {
        return array_map(fn (Request $r): string => (string) $r['text'], self::sent());
    }
}
