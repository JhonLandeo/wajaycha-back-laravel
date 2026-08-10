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

    /** What a credential is replaced by, in any text that reaches a log. */
    private const SECRET_MARKER = '<secreto>';

    /**
     * Below this length a configured value is not a credential — it is a
     * placeholder or an empty-ish leftover, and replacing it would shred the
     * line instead of protecting it.
     */
    private const MIN_SECRET_LENGTH = 8;

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
     *
     * Reports the absence of a key rather than degrading silently — see below.
     */
    public static function id(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '<sin id>';
        }

        $key = self::key();

        if ($key === null) {
            // Se dice en la linea, no se lanza. Sin clave el digest no es un
            // seudonimo sino un hash enumerable, y este marcador no se puede
            // confundir con uno — que era todo el punto de no degradar en
            // silencio. Lanzar, en cambio, rompe rutas escritas para tragar: la
            // revision acotada corroboro que se llevaba puesto el registro de
            // usuarios, donde el User ya quedo creado antes de la llamada.
            return '<id: sin clave>';
        }

        return '<id: '.substr(hash_hmac('sha256', $value, $key), 0, self::DIGEST_LENGTH).'>';
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

    /**
     * The same text with every credential this application holds replaced by a
     * marker.
     *
     * This exists for one reason: the Telegram Bot API puts the bot token in the
     * URL path, and Gemini puts its key in the query string. When an outbound
     * call cannot complete, Guzzle raises a `ConnectionException` whose message
     * ends with the full request URI — so `$exception->getMessage()` carries the
     * credential, and every `Log::error('...: '.$e->getMessage())` writes it to
     * `storage/logs` and ships it to Sentry as a breadcrumb.
     *
     * Callers should still keep secrets out of a message when they can. This is
     * the net under the cases where the value is not theirs to shape, and it is
     * deliberately value-based rather than pattern-based: it replaces what this
     * application actually holds, so it cannot be fooled by a format change.
     * The two patterns below only cover a credential that is no longer the
     * configured one — mid-rotation, or a second bot.
     */
    public static function secrets(?string $text): string
    {
        $text = (string) $text;

        if ($text === '') {
            return '';
        }

        foreach (self::secretValues() as $secret) {
            $text = str_replace($secret, self::SECRET_MARKER, $text);
        }

        // `https://api.telegram.org/bot<digits>:<rest>/method` and any `key=`
        // query parameter, for a credential this process does not have in config.
        $text = (string) preg_replace('#/bot\d+:[A-Za-z0-9_-]+#', '/bot'.self::SECRET_MARKER, $text);

        return (string) preg_replace('#(\bkey=)[^&\s]+#i', '$1'.self::SECRET_MARKER, $text);
    }

    /**
     * Every credential worth replacing, longest first.
     *
     * Longest first matters: if one secret is a substring of another, replacing
     * the shorter one first would leave the tail of the longer one readable.
     *
     * @return array<int, string>
     */
    private static function secretValues(): array
    {
        $candidates = [
            (string) config('services.telegram.bot_token'),
            (string) config('services.telegram.secret_token'),
            (string) config('services.gemini.api_key'),
            (string) config('services.whatsapp.access_token'),
            (string) config('services.whatsapp.verify_token'),
            (string) config('app.key'),
        ];

        $secrets = array_values(array_filter(
            $candidates,
            // A one or two character "secret" would replace half the line. Any
            // real credential clears this by a wide margin.
            fn (string $value): bool => strlen(trim($value)) >= self::MIN_SECRET_LENGTH,
        ));

        usort($secrets, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }

    /** Null when `app.key` is absent, which the caller reports rather than hides. */
    private static function key(): ?string
    {
        $key = (string) config('app.key');

        return trim($key) === '' ? null : $key;
    }
}
