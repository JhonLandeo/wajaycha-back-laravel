<?php

declare(strict_types=1);

use App\Enums\BotMenuAction;
use App\Jobs\ProcessTelegramCapture;
use App\Jobs\ProcessTelegramMenu;
use App\Models\Category;
use App\Models\ChannelIdentity;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.telegram.bot_token', 'test-token');
    config()->set('services.telegram.secret_token', 'test-secret');
    config()->set('coaching.enabled', true);

    Http::fake(fn () => Http::response(['ok' => true], 200));
});

function menuChat(): string
{
    return '55501';
}

function menuLinkedUser(): User
{
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => menuChat(),
    ]);

    return $user;
}

function menuOverBudgetCategoryFor(User $user): Category
{
    $category = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
        'monthly_budget' => 300.0,
        'name' => 'Delivery',
    ]);

    $detail = Detail::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'detail_id' => $detail->id,
        'category_id' => $category->id,
        'type_transaction' => 'expense',
        'amount' => 340.0,
        'date_operation' => now()->toDateTimeString(),
    ]);

    return $category;
}

/** The last outbound sendMessage payload, or null when none was sent. */
function lastSentMessage(): ?array
{
    $sent = null;

    Http::recorded(function ($request) use (&$sent) {
        if (str_contains($request->url(), '/sendMessage')) {
            $sent = $request->data();
        }

        return true;
    });

    return $sent;
}

it('routes /menu to the menu job instead of the capture pipeline', function () {
    Bus::fake();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
        ->postJson('/api/telegram/webhook', [
            'update_id' => 1,
            'message' => ['chat' => ['id' => menuChat()], 'text' => '/menu'],
        ])
        ->assertOk();

    Bus::assertDispatched(ProcessTelegramMenu::class);
    Bus::assertNotDispatched(ProcessTelegramCapture::class);
});

it('still sends ordinary text to the capture pipeline', function () {
    Bus::fake();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
        ->postJson('/api/telegram/webhook', [
            'update_id' => 2,
            'message' => ['chat' => ['id' => menuChat()], 'text' => 'gasté 40 en el mercado'],
        ])
        ->assertOk();

    Bus::assertDispatched(ProcessTelegramCapture::class);
    Bus::assertNotDispatched(ProcessTelegramMenu::class);
});

/**
 * A pressed button arrives as `callback_query`, with no `message` at the top
 * level. Before it was recognised it fell through to the unsupported branch and
 * the bot answered its own button with "no puedo leer ese tipo de mensaje".
 */
it('routes a pressed button to the menu job', function () {
    Bus::fake();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
        ->postJson('/api/telegram/webhook', [
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cbq-1',
                'from' => ['id' => 999],
                'message' => ['chat' => ['id' => menuChat()]],
                'data' => BotMenuAction::HOW_AM_I_DOING->value,
            ],
        ])
        ->assertOk();

    Bus::assertDispatched(ProcessTelegramMenu::class);
    Bus::assertNotDispatched(ProcessTelegramCapture::class);
});

it('offers the keyboard when the menu is opened by command', function () {
    menuLinkedUser();

    (new ProcessTelegramMenu(menuChat()))->handle(
        app(App\Services\Capture\TelegramChannel::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Coaching\BudgetDigestService::class),
    );

    $payload = lastSentMessage();

    expect($payload)->not->toBeNull()
        ->and($payload['reply_markup']['inline_keyboard'][0][0]['callback_data'])
        ->toBe(BotMenuAction::HOW_AM_I_DOING->value);
});

it('answers the pressed button with the budget board', function () {
    $user = menuLinkedUser();
    menuOverBudgetCategoryFor($user);

    (new ProcessTelegramMenu(menuChat(), 'cbq-1', BotMenuAction::HOW_AM_I_DOING->value))->handle(
        app(App\Services\Capture\TelegramChannel::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Coaching\BudgetDigestService::class),
    );

    expect(lastSentMessage()['text'])->toContain('Delivery')->toContain('Ya pasaste');
});

/**
 * The morning digest ships off by default because it starts a conversation
 * nobody asked for. A button IS the asking, so that switch must not silence it.
 */
it('answers even while the morning digest is switched off', function () {
    config()->set('coaching.digest_enabled', false);

    $user = menuLinkedUser();
    menuOverBudgetCategoryFor($user);

    (new ProcessTelegramMenu(menuChat(), 'cbq-1', BotMenuAction::HOW_AM_I_DOING->value))->handle(
        app(App\Services\Capture\TelegramChannel::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Coaching\BudgetDigestService::class),
    );

    expect(lastSentMessage()['text'])->toContain('Delivery');
});

/**
 * Silence is a valid outcome for the coach, which speaks unprompted. It is never
 * a valid answer to a question the user just asked.
 */
it('says everything is fine rather than staying silent', function () {
    menuLinkedUser();

    (new ProcessTelegramMenu(menuChat(), 'cbq-1', BotMenuAction::HOW_AM_I_DOING->value))->handle(
        app(App\Services\Capture\TelegramChannel::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Coaching\BudgetDigestService::class),
    );

    expect(lastSentMessage()['text'])->toContain('Vas bien');
});

it('does not claim everything is fine while coaching is switched off', function () {
    config()->set('coaching.enabled', false);

    $user = menuLinkedUser();
    menuOverBudgetCategoryFor($user);

    (new ProcessTelegramMenu(menuChat(), 'cbq-1', BotMenuAction::HOW_AM_I_DOING->value))->handle(
        app(App\Services\Capture\TelegramChannel::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Coaching\BudgetDigestService::class),
    );

    expect(lastSentMessage()['text'])->not->toContain('Vas bien');
});

/**
 * Buttons outlive deploys: a message sent months ago still delivers its old
 * callback_data when someone scrolls up and taps it.
 */
it('reopens the menu when an unknown action arrives', function () {
    menuLinkedUser();

    (new ProcessTelegramMenu(menuChat(), 'cbq-1', 'm:gone'))->handle(
        app(App\Services\Capture\TelegramChannel::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Coaching\BudgetDigestService::class),
    );

    expect(lastSentMessage()['reply_markup']['inline_keyboard'])->not->toBeEmpty();
});

it('tells an unlinked sender to link before anything else', function () {
    (new ProcessTelegramMenu(menuChat(), 'cbq-1', BotMenuAction::HOW_AM_I_DOING->value))->handle(
        app(App\Services\Capture\TelegramChannel::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Coaching\BudgetDigestService::class),
    );

    expect(lastSentMessage()['text'])->toContain('no está vinculada');
});

/**
 * Telegram spins an indicator on the tapped button until answerCallbackQuery
 * arrives. It is closed first, before anything that could fail, so a broken
 * answer never leaves the button stuck.
 */
it('closes the callback query before answering', function () {
    menuLinkedUser();

    $calls = [];
    Http::fake(function ($request) use (&$calls) {
        $calls[] = str_contains($request->url(), '/answerCallbackQuery') ? 'answer' : 'send';

        return Http::response(['ok' => true], 200);
    });

    (new ProcessTelegramMenu(menuChat(), 'cbq-1', BotMenuAction::HOW_AM_I_DOING->value))->handle(
        app(App\Services\Capture\TelegramChannel::class),
        app(App\Services\Capture\ChannelIdentityResolver::class),
        app(App\Services\Coaching\BudgetDigestService::class),
    );

    expect($calls[0])->toBe('answer');
});

it('never sends a callback_data past telegram 64 byte limit', function () {
    foreach (BotMenuAction::cases() as $action) {
        expect(strlen($action->value))->toBeLessThanOrEqual(64)
            ->and($action->value)->not->toBe('');
    }
});
