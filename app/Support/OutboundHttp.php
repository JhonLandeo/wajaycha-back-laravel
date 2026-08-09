<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The single door every outbound HTTP call goes through.
 *
 * Before this class no call in `app/` declared a timeout or a retry — not
 * Gemini, not Telegram, not Meta. Guzzle's own default still cut the wire, but
 * nobody had chosen it, and nothing distinguished "this receipt is unreadable"
 * from "Gemini returned 503 for four seconds". The user saw the same
 * "no pude leer ese envío" for both.
 *
 * A helper rather than a `Http::macro()`: a macro is invisible to PHPStan, and
 * static analysis at level 6 is a CI gate here. A call that skips this class is
 * a call with no policy, and it should be visible as such at the call site.
 */
final class OutboundHttp
{
    /**
     * Builds the client for a named profile from `config/http.php`.
     *
     * @param  string  $profile  A key under `http.profiles`.
     */
    public static function to(string $profile): PendingRequest
    {
        $config = self::configFor($profile);

        return Http::timeout($config['timeout'])
            ->connectTimeout($config['connect_timeout'])
            ->retry(
                $config['retries'] + 1,
                fn (int $attempt, Throwable $e): int => self::delayFor($attempt, $e, $config),
                fn (Throwable $e): bool => self::isTransient($e),
                // The whole point. With `throw: true` — Laravel's default the
                // moment a retry is configured — a 4xx or 5xx that survives the
                // last attempt comes back as a thrown RequestException instead
                // of a response. Every caller in this codebase is written as
                // `if (! $response->successful())`, so leaving this on would
                // convert each of those branches into an uncaught exception and
                // turn a logged failure into a failed job. Retries are added
                // here; error handling is left exactly where it was.
                throw: false,
            );
    }

    /**
     * Whether the failure is worth a second attempt.
     *
     * The distinction is not cosmetic. A 400 means the request was wrong and it
     * will be just as wrong the next time — retrying it burns latency and, on
     * Gemini, real tokens. A 429 or a 5xx means the request was fine and the
     * far side was not.
     */
    private static function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! $e instanceof RequestException) {
            return false;
        }

        $status = $e->response->status();

        return $status === 429 || $status >= 500;
    }

    /**
     * Exponential backoff, overridden by whatever the server asked for.
     *
     * Retrying a rate limit on our own schedule is how a 429 becomes a ban:
     * the far side already said when to come back, and ignoring it means every
     * attempt lands inside the window it just closed.
     *
     * @param  array{timeout: int, connect_timeout: int, retries: int, retry_base_delay_ms: int, retry_max_delay_ms: int}  $config
     */
    private static function delayFor(int $attempt, Throwable $e, array $config): int
    {
        $base = $config['retry_base_delay_ms'];

        if ($base <= 0) {
            // Testing sets this to zero so the suite exercises the retry path
            // without paying for it in wall clock.
            return 0;
        }

        $requested = self::retryAfterMs($e);
        $backoff = $requested ?? (int) ($base * 2 ** ($attempt - 1));

        return min($backoff, $config['retry_max_delay_ms']);
    }

    /**
     * The wait the server asked for, in milliseconds, or null if it asked for
     * nothing.
     *
     * Two places to look, because the two dependencies answer differently:
     * Gemini sends the standard `Retry-After` header, Telegram puts
     * `parameters.retry_after` in the JSON body and returns the header only
     * sometimes.
     */
    private static function retryAfterMs(Throwable $e): ?int
    {
        if (! $e instanceof RequestException) {
            return null;
        }

        $header = $e->response->header('Retry-After');

        if (is_numeric($header)) {
            return (int) ((float) $header * 1000);
        }

        $body = $e->response->json('parameters.retry_after');

        return is_numeric($body) ? (int) ((float) $body * 1000) : null;
    }

    /**
     * @return array{timeout: int, connect_timeout: int, retries: int, retry_base_delay_ms: int, retry_max_delay_ms: int}
     */
    private static function configFor(string $profile): array
    {
        /** @var array<string, int> $defaults */
        $defaults = config('http.defaults', []);

        /** @var array<string, int>|null $overrides */
        $overrides = config("http.profiles.{$profile}");

        if ($overrides === null) {
            // A typo'd profile name must not silently downgrade to "no policy",
            // which is the exact state this class exists to end. It is a
            // programming error and it fails on the first call, in development,
            // rather than becoming an unbounded request in production.
            throw new \InvalidArgumentException(
                "Perfil HTTP saliente desconocido: '{$profile}'. Los perfiles se declaran en config/http.php."
            );
        }

        /** @var array{timeout: int, connect_timeout: int, retries: int, retry_base_delay_ms: int, retry_max_delay_ms: int} $merged */
        $merged = array_merge($defaults, $overrides);

        return $merged;
    }
}
