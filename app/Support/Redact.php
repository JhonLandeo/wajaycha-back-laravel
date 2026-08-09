<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Turns values that identify a person, or describe what they spent, into
 * something a log line can carry.
 *
 * The application used to write phone numbers, Telegram chat ids, merchant
 * names, amounts and the user's own message text straight into `Log::info`.
 * Two things make that worse than it looks. `storage/logs` is plain text with
 * no retention rule, and `config/sentry.php` ships
 * `breadcrumbs.logs => env(..., true)`: every one of those lines is attached as
 * a breadcrumb to the next exception and leaves the server for a third party.
 *
 * The replacement is not "log less". A log that cannot answer "which user, which
 * merchant, which run" is not worth keeping either. What is kept is the ability
 * to CORRELATE — the same input always produces the same token — and what is
 * dropped is the ability to READ.
 */
final class Redact
{
    /**
     * Ten hex characters. Enough that two distinct users colliding is not a
     * practical concern at this scale, short enough to stay readable in a line.
     */
    private const DIGEST_LENGTH = 10;

    /**
     * A stable pseudonym for a phone number, a chat id or any other handle that
     * points at a person.
     *
     * Keyed on purpose, and this is the part worth not simplifying: a plain
     * `sha256()` of a Peruvian mobile number is not anonymous. The whole space
     * is about 10^8 values, so anyone holding the log can enumerate it in
     * seconds and recover every number. An HMAC under `app.key` cannot be
     * reversed that way without the key, which does not live in the log file.
     *
     * The token is stable for as long as `APP_KEY` is. Rotating the key
     * re-pseudonymises everything and old lines stop correlating with new ones —
     * a real consequence, not a footnote, if a key rotation is ever needed.
     *
     * To find a user's lines, hash their identifier with the same key rather
     * than grepping for the raw value: `Redact::id('+51999888777')`.
     */
    public static function id(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '<sin id>';
        }

        return '#'.substr(hash_hmac('sha256', $value, self::key()), 0, self::DIGEST_LENGTH);
    }

    /**
     * The shape of a free-text value, never its content.
     *
     * A merchant name and whatever the user typed about their spending are the
     * two things this application must not write down. What is still useful when
     * something goes wrong is whether anything arrived at all and roughly how
     * much, which is what survives here.
     */
    public static function text(?string $value): string
    {
        if ($value === null) {
            return '<nulo>';
        }

        $length = mb_strlen(trim($value));

        return $length === 0 ? '<vacío>' : "<texto: {$length} caracteres>";
    }

    private static function key(): string
    {
        return (string) config('app.key');
    }
}
