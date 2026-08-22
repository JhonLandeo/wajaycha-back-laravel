<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\GoogleIdentity;
use App\Exceptions\Auth\GoogleIdentityException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Finds, links or creates the account behind a verified Google identity.
 *
 * Three paths, tried in this order:
 *
 * 1. **Already linked** — `google_id` matches. Nothing to decide.
 * 2. **Same email, no link yet** — the account was created with a password and
 *    its owner is now arriving through Google. Link it.
 * 3. **Nobody** — create the account.
 *
 * **Why linking by email is safe here, and only here.** Matching an identity
 * provider's email against a local account is an account-takeover primitive: if
 * anyone could claim to own `someone@example.com`, path 2 would hand them that
 * someone's finances. It is safe in this codebase for exactly one reason —
 * {@see \App\Services\Auth\GoogleIdTokenVerifier} refuses any token whose
 * `email_verified` claim is not true, so reaching this class already means Google
 * confirmed the address belongs to that account. Remove that check and this
 * becomes a vulnerability, not a convenience.
 *
 * The account created on path 3 gets its default categories, tags and Pareto
 * bands for free: `User::created` is observed by {@see \App\Observers\UserObserver},
 * which is why the insert goes through the model and not through a query builder.
 *
 * Concurrency is settled by the database, not here. Two simultaneous first logins
 * would both fall through to path 3; the UNIQUE indexes on `google_id` and
 * `email` are what stop the second one from writing a duplicate.
 */
final class ResolveGoogleUserAction
{
    public function execute(GoogleIdentity $identity): User
    {
        $linked = User::query()->where('google_id', $identity->sub)->first();

        if ($linked instanceof User) {
            return $linked;
        }

        $byEmail = User::query()->where('email', $identity->email)->first();

        if ($byEmail instanceof User) {
            return $this->link($byEmail, $identity);
        }

        $user = new User;

        $user->forceFill([
            'name' => $identity->firstName,
            'last_name' => $identity->lastName,
            'email' => $identity->email,
            'google_id' => $identity->sub,
            // Google already did the verifying. Leaving this null would leave the
            // account permanently unverified for `MustVerifyEmail`.
            'email_verified_at' => now(),
            // No password, and no invented one. See the migration.
            'password' => null,
        ])->save();

        return $user;
    }

    private function link(User $user, GoogleIdentity $identity): User
    {
        // The address is ours but it already points at a DIFFERENT Google account.
        // That happens when a Workspace address is deleted and handed to someone
        // new, and silently repointing the link would give that someone the
        // previous owner's account. Refuse and leave a trail.
        if ($user->google_id !== null && $user->google_id !== $identity->sub) {
            Log::warning('Google identity conflict on an existing account.', [
                'user_id' => $user->id,
                'linked_sub' => $user->google_id,
                'incoming_sub' => $identity->sub,
            ]);

            throw GoogleIdentityException::alreadyLinkedElsewhere();
        }

        $user->forceFill([
            'google_id' => $identity->sub,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }
}
