<?php

declare(strict_types=1);

use App\Actions\Capture\RegisterCapturedTransactionAction;
use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Jobs\ProcessTelegramCapture;
use App\Models\Category;
use App\Models\ChannelIdentity;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AI\GeminiTextService;
use App\Services\AI\GeminiVisionService;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\ChannelLinkTokenRedeemer;
use App\Services\Coaching\FinancialCoachingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 (design.md §5.3): the capture-time coaching hook on
 * ProcessTelegramCapture::handle(). This file is deliberately self-contained
 * (own helper functions, own fixtures) rather than reusing
 * tests/Feature/Telegram/ProcessTelegramCaptureTest.php's file-local
 * `telegramSender()`/`runTelegramJob()`/`lastTelegramReply()` — those are
 * top-level functions that only exist once that file has been loaded, so a
 * sibling file depending on them fatals when run on its own (the same reason
 * tests/Support/CaptureFixtures.php exists as a class instead).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.telegram.bot_token', 'BOT-TOKEN');
    config()->set('services.telegram.bot_username', 'wajaycha_bot');
});

/** A user linked on Telegram — reachable by both the capture job and the coach. */
function coachingCaptureSender(string $chatId = '555000111'): User
{
    $user = User::factory()->create();

    ChannelIdentity::factory()->create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'external_id' => $chatId,
    ]);

    return $user;
}

/**
 * A leaf expense category the capture-time categorizer can find deterministically.
 * `CategorizationService::findCategory()`'s message-ilike path requires
 * `whereNotNull('parent_id')` (only matches child categories), so a parent is
 * created purely to satisfy that FK — it never appears in a budget snapshot
 * itself because `get_monthly_category_budget_report()` excludes any category
 * that has children.
 */
function coachingCategoryFor(User $user, string $name, float $budget): Category
{
    $parent = Category::factory()->create([
        'user_id' => $user->id,
        'type' => 'expense',
    ]);

    return Category::factory()->asChild($parent->id)->create([
        'user_id' => $user->id,
        'name' => $name,
        'type' => 'expense',
        'monthly_budget' => $budget,
    ]);
}

function coachingParsedMovement(float $amount, string $categoryName, string $type = 'expense'): ParsedReceiptDTO
{
    return new ParsedReceiptDTO(
        isValid: true,
        amount: $amount,
        destination: $type === 'expense' ? 'Comercio de prueba' : null,
        origin: $type === 'expense' ? null : 'Sueldo',
        dateOperation: now()->toIso8601String(),
        type: $type,
        // Matched verbatim by CategorizationService's message-ilike path.
        message: $categoryName,
    );
}

function runCoachingCaptureJob(string $chatId, ParsedReceiptDTO $parsed): void
{
    $vision = Mockery::mock(GeminiVisionService::class);
    $vision->shouldReceive('parseReceipt')->andReturn($parsed);

    $textService = Mockery::mock(GeminiTextService::class);
    $textService->shouldReceive('parseText')->andReturn($parsed);

    (new ProcessTelegramCapture($chatId, 'texto de captura'))->handle(
        app(CaptureChannelRegistry::class),
        app(ChannelIdentityResolver::class),
        app(ChannelLinkTokenRedeemer::class),
        $vision,
        $textService,
        app(RegisterCapturedTransactionAction::class),
    );
}

/** Every outbound Telegram sendMessage, in call order. */
function telegramMessagesSent(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/sendMessage'))
        ->map(fn ($pair) => (string) $pair[0]['text'])
        ->values()
        ->all();
}

it('sends exactly one coaching message, after the confirmation, when the capture crosses the pace line', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = coachingCaptureSender();
    coachingCategoryFor($user, 'Restaurantes Coaching', budget: 20.0);

    // Counterfactual (design.md §5.3): with no prior spend this month, "spent
    // - amount" is 0 (skipped by the evaluator), so this exact transaction is
    // what pushes the category from nothing to over_budget.
    runCoachingCaptureJob('555000111', coachingParsedMovement(50.0, 'Restaurantes Coaching'));

    $messages = telegramMessagesSent();

    expect(Transaction::sole()->amount)->toBe('50.00')
        ->and($messages)->toHaveCount(2)
        ->and($messages[0])->toContain('Registrado')
        ->and($messages[1])->not->toContain('Registrado')
        ->and($messages[1])->toContain('Restaurantes Coaching');
});

it('sends only the confirmation when the capture does not cross the pace line', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = coachingCaptureSender();
    // Budget is far above spend, and — being the only transaction this month —
    // the category is structurally lumpy (100% of spend is this one purchase),
    // which suppresses projection too. Neither path produces an observation,
    // deterministically, regardless of which day of the month the suite runs on.
    coachingCategoryFor($user, 'Ocio Coaching', budget: 1000.0);

    runCoachingCaptureJob('555000111', coachingParsedMovement(25.50, 'Ocio Coaching'));

    $messages = telegramMessagesSent();

    expect(Transaction::sole()->amount)->toBe('25.50')
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('Registrado');
});

it('never coaches an income transaction, even when it lands in a matching category', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $user = coachingCaptureSender();
    coachingCategoryFor($user, 'Salario Coaching', budget: 20.0);

    $this->mock(FinancialCoachingService::class, function ($mock) {
        $mock->shouldReceive('speak')->never();
    });

    runCoachingCaptureJob('555000111', coachingParsedMovement(500.0, 'Salario Coaching', type: 'income'));

    $messages = telegramMessagesSent();

    expect(Transaction::sole()->type_transaction)->toBe('income')
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('Registrado');
});

it('never coaches a transaction with category_id NULL', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    coachingCaptureSender();

    $this->mock(FinancialCoachingService::class, function ($mock) {
        $mock->shouldReceive('speak')->never();
    });

    // No category or keyword rule exists anywhere for this user, so
    // CategorizationService::findCategory() returns null (the same path the
    // shipped ProcessTelegramCaptureTest suite already exercises).
    runCoachingCaptureJob('555000111', coachingParsedMovement(30.0, 'Categoria inexistente'));

    $messages = telegramMessagesSent();

    expect(Transaction::sole()->category_id)->toBeNull()
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('Registrado');
});

it('catches a coaching exception, keeps the transaction, still confirms, and does not retry', function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    Log::spy();

    $user = coachingCaptureSender();
    coachingCategoryFor($user, 'Compras Coaching', budget: 20.0);

    $this->mock(FinancialCoachingService::class, function ($mock) {
        // Mirrors the composer's real failure mode (design.md's own
        // documented throw on a `projected_over` with no projection or an
        // unknown band) without needing to reconstruct that exact fixture —
        // the guard must survive ANY Throwable from speak(), not just this one.
        $mock->shouldReceive('speak')->once()->andThrow(new RuntimeException('coaching boom'));
    });

    // Calling handle() directly is itself the assertion that nothing escapes
    // the guard: if the exception were rethrown, this line would fail the test.
    runCoachingCaptureJob('555000111', coachingParsedMovement(50.0, 'Compras Coaching'));

    $messages = telegramMessagesSent();

    expect(Transaction::sole()->amount)->toBe('50.00')
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toContain('Registrado');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'coaching boom'))
        ->once();
});
