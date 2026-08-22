<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

/**
 * A Google account, after its ID token has been verified.
 *
 * Nothing untrusted reaches this class. It only exists once the signature has
 * been checked against Google's keys, the audience matched our client ID and the
 * email came back verified — so anything downstream can treat these fields as
 * facts instead of input.
 */
final readonly class GoogleIdentity
{
    public function __construct(
        /**
         * Google's `sub` claim: the account's permanent identifier. This — not
         * the email — is what gets persisted, because a user can change their
         * Google email and would otherwise look like a stranger on the next login.
         */
        public string $sub,
        public string $email,
        public string $firstName,
        public ?string $lastName,
    ) {}
}
