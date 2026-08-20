<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The commands the bot declares to Telegram.
 *
 * Distinct from {@see BotMenuAction}, and the difference is who renders it.
 * A menu action is a button the bot draws inside a message it sends. A command
 * is something **Telegram itself** draws: once published with `setMyCommands`,
 * the client shows the list behind the `/` next to the input field and turns the
 * paperclip row into a labelled Menu button. That is the entire point of this
 * enum existing — a button nobody has been sent yet is invisible, and until now
 * the only way to reach the menu was to already know it was there.
 *
 * The backing value is the wire form and carries Telegram's own constraints:
 * **1 to 32 characters, lowercase letters, digits and underscores, and no
 * leading slash** — the API rejects the payload otherwise. The slash exists only
 * in what the user types, which is why {@see slashed()} adds it rather than the
 * value carrying it.
 *
 * Only commands the bot actually answers belong here. Publishing one it ignores
 * is worse than publishing nothing: the client advertises it, the user taps it,
 * and the message falls through to the capture pipeline to be read as a receipt.
 */
enum BotCommand: string
{
    /**
     * What every Telegram client puts a full-width START button on the first
     * time a chat is opened. It is the single most pressed thing in the product
     * and, until this enum, the pipeline had no branch for it: bare `/start`
     * does not match `ProcessTelegramCapture::LINK_PREFIX` (`'/start '`, with the
     * space), so a linked user pressing it paid for a Gemini reading and was told
     * their greeting did not look like a movement with an amount.
     */
    case START = 'start';

    /** The catalogue of questions the bot can answer. */
    case MENU = 'menu';

    /**
     * What Telegram lists beside the command. Kept to one short line: the client
     * renders it on a single row and truncates the rest.
     *
     * `MENU` says "gastos" and stays that way. The four buttons behind it are all
     * spending questions ({@see BotMenuAction}), so widening this line to promise
     * income would be the same defect in the other direction — copy describing
     * something the code does not do. The capture path is where income lives, and
     * that is where it is now announced.
     */
    public function description(): string
    {
        return match ($this) {
            self::START => 'Empezar: registra tus gastos y tus ingresos',
            self::MENU => 'Preguntas que puedo responder sobre tus gastos',
        };
    }

    /** The form the user types, and the form Telegram delivers in `message.text`. */
    public function slashed(): string
    {
        return '/'.$this->value;
    }

    /**
     * Whether an inbound text is a command that should open the menu.
     *
     * Exact match, never a prefix, and that is load-bearing: `/start <token>` is
     * the account-linking message and has to keep reaching
     * {@see \App\Jobs\ProcessTelegramCapture}, which is the only thing that can
     * redeem it. Matching on a prefix here would swallow every link attempt and
     * leave nobody able to connect an account at all.
     */
    public static function opensMenu(string $text): bool
    {
        return in_array(
            trim($text),
            [self::MENU->slashed(), self::START->slashed()],
            true,
        );
    }

    /**
     * The payload for Telegram's `setMyCommands`.
     *
     * @return array<int, array<string, string>>
     */
    public static function registration(): array
    {
        return array_map(
            fn (self $command): array => [
                'command' => $command->value,
                'description' => $command->description(),
            ],
            self::cases(),
        );
    }
}
