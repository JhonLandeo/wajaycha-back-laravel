<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Validates a login attempt and owns the per-account half of the brute-force
 * defence.
 *
 * The previous version of this class carried an `authenticate()` method that
 * held the whole throttle sequence and was never called by anything: the
 * controller validated the request and then ran `JWTAuth::attempt()` on its own,
 * so no counter was ever incremented and no lockout was ever evaluated. It also
 * authenticated through `Auth::attempt()` — the session guard, not the JWT guard
 * this API actually issues tokens from — and reported failures through
 * `__('auth.failed')`, a key with no `lang/` directory to resolve against.
 *
 * The pieces are now granular and the controller drives them explicitly around
 * its own `JWTAuth::attempt()` call. A method that must be invoked to have any
 * effect should be impossible to leave out by accident; three named calls at the
 * call site are visible in a way one delegated method was not.
 */
class LoginRequest extends FormRequest
{
    /**
     * Failed attempts tolerated for one (email, IP) pair before it is locked.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * How long a locked pair stays locked.
     *
     * Laravel's default decay is one minute, which caps an attacker at five
     * passwords per minute — and leaves them free to keep going forever, which
     * is 7,200 attempts a day against a single account. This is a ledger of
     * someone's bank movements. A person who genuinely forgot their password
     * waits fifteen minutes; a script does not get a usable rate.
     */
    public const DECAY_SECONDS = 900;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'El formato del correo electrónico no es válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ];
    }

    /**
     * Whether this (email, IP) pair has spent its attempts.
     *
     * This is the per-account half of the defence and it is deliberately NOT
     * sufficient on its own: the key contains the email, so spraying a single
     * common password across many addresses opens a fresh counter every time
     * and never trips this one. The per-IP `throttle:` middleware on the route
     * is the other half. Neither replaces the other — removing either one
     * leaves a working attack.
     */
    public function isRateLimited(): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS);
    }

    public function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    /**
     * Called only after credentials were accepted, so a legitimate user who
     * mistyped their password a few times is not still carrying those failures
     * into the next quarter of an hour.
     */
    public function clearRateLimiter(): void
    {
        RateLimiter::clear($this->throttleKey());
    }

    public function secondsUntilRetry(): int
    {
        return RateLimiter::availableIn($this->throttleKey());
    }

    /**
     * Emitted so a lockout is observable from outside this class — Sentry
     * breadcrumbs and any future listener see it without this request having to
     * know who is watching.
     */
    public function recordLockout(): void
    {
        event(new Lockout($this));
    }

    /**
     * Prefixed so this counter can never collide with another feature that
     * happens to key on the same email and address.
     */
    public function throttleKey(): string
    {
        return 'login|'.Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
