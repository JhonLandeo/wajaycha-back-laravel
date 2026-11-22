<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a button in the bot's menu asks for.
 *
 * The backing value travels as Telegram's `callback_data`, which is capped at
 * **64 bytes** — a longer value is rejected outright with `BUTTON_DATA_INVALID`,
 * so this is a hard protocol limit and not a style preference. Hence the terse
 * `m:` prefixed keys: they leave room to append an argument later (a category id,
 * a month) without any of them approaching the ceiling.
 *
 * The value is also a permanent commitment. A button lives inside a message that
 * stays in the user's chat history forever, so a key renamed today keeps arriving
 * tomorrow from every message already sent. Renaming one means accepting that the
 * old value must still resolve — which is why {@see tryFromCallback()} answers
 * null instead of throwing.
 *
 * Only the actions that can actually be answered belong here. A menu that offers
 * a question and then cannot answer it is worse than a smaller menu.
 */
enum BotMenuAction: string
{
    /**
     * The standing budget position: what is already over, what is heading there,
     * what is left of a yearly envelope. Answered from `BudgetDigestService`,
     * which already composes exactly this for the morning message.
     */
    case HOW_AM_I_DOING = 'm:how';

    /**
     * What is left of every monthly budget, divided by the days left in the month.
     * Answered from `DailyAllowanceService`.
     *
     * The question that reads a budget forwards instead of backwards. Everything
     * else this bot says is about spending that already happened, which can only
     * ever be a verdict; this one lands before the decision, which is the only
     * moment a figure can still change it.
     */
    case HOW_MUCH_TODAY = 'm:today';

    /**
     * What moved between this month so far and the same stretch of the last one.
     * Answered from `MonthOverMonthService`.
     *
     * The only question here that needs no budget. The other two are silent for a
     * user who never set one, and that user is the majority — this one works from
     * spending alone, which is the only thing everybody has.
     */
    case WHAT_CHANGED = 'm:changed';

    /**
     * How much of a typical month simply arrives, and how much is chosen.
     * Answered from `SpendingRhythmService`.
     *
     * No longer offered in the menu: the owner found the answer more interesting
     * than useful. The case is kept so buttons in messages already sent still
     * resolve to this answer instead of the unknown-action fallback. See
     * {@see self::menu()} and {@see \App\Enums\SpendingRhythm}.
     */
    case FIXED_OR_CHOSEN = 'm:fixed';

    /**
     * "Cobré hoy": the salary arrived, send me the plan. Answered by the payday
     * handling, not by {@see \App\Jobs\ProcessTelegramMenu}.
     *
     * Its value uses the `p:` prefix on purpose: the webhook routes that prefix to
     * the payday job, which can authorise against the user's cycles, while an `m:`
     * key would land in the menu job's unknown-action fallback. It carries no cycle
     * id because only the latest cycle is ever answerable.
     *
     * Not offered in the menu any more: the payday conversation draws the button
     * itself after a deferral, where it has a cycle to answer against. The case
     * stays so {@see self::tryFromCallback()} still maps `p:paid`, and so the
     * payday job keeps one name for the value. The value is
     * {@see \App\DTOs\PaydayPlan\PaydayCallback::PAID}.
     */
    case PAYDAY_ARRIVED = 'p:paid';

    /** The label the user reads on the button. */
    public function label(): string
    {
        return match ($this) {
            self::HOW_AM_I_DOING => '¿Cómo voy?',
            self::HOW_MUCH_TODAY => '¿Cuánto puedo gastar hoy?',
            self::WHAT_CHANGED => '¿Qué cambió desde el mes pasado?',
            self::FIXED_OR_CHOSEN => '¿Qué es fijo y qué decido yo?',
            self::PAYDAY_ARRIVED => 'Cobré hoy',
        };
    }

    /**
     * Resolves inbound `callback_data`, answering null for anything unknown.
     *
     * Unknown is a normal case, not an attack: buttons outlive deploys, and a
     * message sent before an action was removed will still deliver its old value
     * when someone scrolls up and taps it months later. The caller answers that
     * politely rather than failing a job.
     */
    public static function tryFromCallback(?string $data): ?self
    {
        return $data === null ? null : self::tryFrom($data);
    }

    /**
     * The actions the menu offers, in the order they appear.
     *
     * Deliberately not `cases()`. Two answers live in the enum without being
     * offered here, and for different reasons:
     *
     * - `FIXED_OR_CHOSEN` is retired from the menu. The case stays so a button
     *   in an old message still resolves instead of falling into the unknown
     *   branch, but new menus no longer ask the question.
     * - `PAYDAY_ARRIVED` belongs to the payday conversation, which draws its own
     *   "Cobré hoy" keyboard after a deferral ({@see \App\Services\PaydayPlan\PaydayPlanComposer::deferred()}).
     *   Offering it here as well duplicated the button for users with no plan,
     *   who could only ever be told they had none.
     *
     * Adding a case therefore does not silently add a button: the test suite
     * pins the offered set to this list.
     *
     * @return array<int, self>
     */
    public static function menu(): array
    {
        return [
            self::HOW_AM_I_DOING,
            self::HOW_MUCH_TODAY,
            self::WHAT_CHANGED,
        ];
    }

    /**
     * The menu as Telegram's `inline_keyboard`: an array of rows, each row an
     * array of buttons.
     *
     * One button per row. A row of several buttons truncates their labels on a
     * narrow phone, and these labels are questions, not verbs — half a question
     * is not a button anyone presses.
     *
     * @return array<int, array<int, array<string, string>>>
     */
    public static function keyboard(): array
    {
        return array_map(
            fn (self $action): array => [[
                'text' => $action->label(),
                'callback_data' => $action->value,
            ]],
            self::menu(),
        );
    }
}
