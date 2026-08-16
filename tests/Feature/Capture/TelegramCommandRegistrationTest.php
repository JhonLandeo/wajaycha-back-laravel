<?php

declare(strict_types=1);

/**
 * The menu only exists for a user who can find it, and `setMyCommands` is the one
 * call that puts it in front of someone who has never been sent a button.
 *
 * These cases guard the two ways that quietly fails: a payload Telegram rejects
 * on format, and a deploy that reports success while having published nothing.
 */

use App\Enums\BotCommand;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.telegram.bot_token', 'test-token');
});

/**
 * Each case installs its own fake rather than inheriting one from `beforeEach`.
 *
 * `Http::fake()` MERGES stub callbacks instead of replacing them, and the first
 * one registered that matches is the one that answers. A shared success fake set
 * up here would therefore silently outrank the failure fake of the case that
 * needs one — which is exactly how the rejection case below first passed while
 * asserting nothing.
 */
function fakeTelegramOk(): void
{
    Http::fake(fn () => Http::response(['ok' => true, 'result' => true], 200));
}

/** The `commands` array of the last setMyCommands call, or null. */
function publishedCommands(): ?array
{
    $payload = null;

    Http::recorded(function ($request) use (&$payload) {
        if (str_contains($request->url(), '/setMyCommands')) {
            $payload = $request->data()['commands'] ?? null;
        }

        return true;
    });

    return $payload;
}

it('publica la lista entera del enum', function () {
    fakeTelegramOk();

    $this->artisan('app:register-telegram-commands')->assertExitCode(0);

    expect(publishedCommands())->toBe(BotCommand::registration());
});

/**
 * Telegram rejects the whole payload — every command, not just the offending one —
 * when any entry breaks the format, so a bad case added later takes the menu down
 * with it. The rule is the API's, not a style preference: 1 to 32 characters,
 * lowercase letters, digits and underscores, no leading slash.
 */
it('cada comando respeta el formato que exige telegram', function () {
    foreach (BotCommand::cases() as $command) {
        expect($command->value)
            ->toMatch('/^[a-z][a-z0-9_]{0,31}$/', "el comando {$command->value} no es publicable")
            ->and(strlen($command->description()))->toBeGreaterThan(0)
            ->and(strlen($command->description()))->toBeLessThanOrEqual(256);
    }
});

it('no publica nada en dry-run', function () {
    fakeTelegramOk();

    $this->artisan('app:register-telegram-commands', ['--dry-run' => true])->assertExitCode(0);

    Http::assertNothingSent();
});

/**
 * Without a token the URL is `.../bot/setMyCommands` and Telegram answers 404,
 * which reads in the log as a rejected list. The command says what actually
 * happened, and says it before spending the request.
 */
it('falla sin publicar cuando no hay bot token', function () {
    fakeTelegramOk();
    config()->set('services.telegram.bot_token', '');

    $this->artisan('app:register-telegram-commands')->assertExitCode(1);

    Http::assertNothingSent();
});

/**
 * The one case the rest of this feature depends on: a deploy that exits 0 while
 * having published nothing leaves a menu nobody can reach, which is
 * indistinguishable from never having run the command.
 */
it('devuelve un codigo de salida distinto de cero cuando telegram rechaza la lista', function () {
    Http::fake(fn () => Http::response(['ok' => false, 'description' => 'Bad Request'], 400));

    $this->artisan('app:register-telegram-commands')->assertExitCode(1);
});

it('abre el menu con los comandos publicados y con nada mas', function () {
    expect(BotCommand::opensMenu('/menu'))->toBeTrue()
        ->and(BotCommand::opensMenu('/start'))->toBeTrue()
        ->and(BotCommand::opensMenu('  /menu  '))->toBeTrue()
        ->and(BotCommand::opensMenu('gasté 40 en el mercado'))->toBeFalse()
        ->and(BotCommand::opensMenu('/menuda cosa'))->toBeFalse();
});

/**
 * Prefix matching here would swallow every account-linking message, and nothing
 * downstream could redeem one: `ProcessTelegramMenu` has no redeemer. The bot
 * would answer politely and no account could ever be connected.
 */
it('no se queda con el /start que trae un token de vinculacion', function () {
    expect(BotCommand::opensMenu('/start 4f3a9c'))->toBeFalse();
});
