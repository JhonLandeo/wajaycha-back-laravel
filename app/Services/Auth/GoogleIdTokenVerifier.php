<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Auth\GoogleIdentity;
use App\Exceptions\Auth\GoogleIdentityException;
use App\Support\OutboundHttp;
use Exception;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Turns the credential the browser got from Google into a verified identity.
 *
 * **Why verify locally instead of asking Google.** Google exposes a `tokeninfo`
 * endpoint that validates a token for you, and it is the wrong tool here: it is
 * documented for debugging, it is rate limited, and it puts a network round trip
 * — plus a new way to be down — in the middle of every single login. The ID
 * token is a signed JWT; checking a signature is local work.
 *
 * **What is actually being checked**, in the order it matters:
 *
 * 1. The signature, against Google's published keys. `JWK::parseKeySet()` pins
 *    each key to the algorithm the key itself declares, which is what closes the
 *    algorithm-confusion hole — a token that says `"alg": "none"` or asks to be
 *    verified with HMAC against the public key is rejected because the key set,
 *    not the token, decides.
 * 2. `aud` — that the token was minted for THIS application. Without it any valid
 *    Google token from any other site would open an account here, and those are
 *    handed out to anyone who registers a client.
 * 3. `iss` — that it came from Google's issuer.
 * 4. `email_verified` — see {@see GoogleIdentityException::emailNotVerified()}.
 *
 * Expiry is enforced by `JWT::decode()` itself.
 */
final class GoogleIdTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** Google mints tokens under both spellings and treats them as equivalent. */
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    private const CACHE_KEY = 'auth:google:jwks';

    /**
     * An hour. Google rotates these keys on its own schedule and publishes the
     * new one before it starts signing with it, so a stale set is normal and
     * recoverable — {@see keySet()} refetches on sight of an unknown `kid`
     * rather than waiting for this to expire.
     */
    private const CACHE_TTL_SECONDS = 3600;

    public function verify(string $credential): GoogleIdentity
    {
        $clientId = config('services.google.client_id');

        if (! is_string($clientId) || $clientId === '') {
            throw GoogleIdentityException::notConfigured();
        }

        $payload = $this->decode($credential);

        $issuer = $this->stringClaim($payload, 'iss');

        if (! in_array($issuer, self::ISSUERS, true)) {
            throw GoogleIdentityException::untrustedIssuer($issuer);
        }

        // `hash_equals` and not `===`: the comparison is against a value the
        // caller controls, and constant time costs nothing here.
        if (! hash_equals($clientId, $this->stringClaim($payload, 'aud'))) {
            throw GoogleIdentityException::audienceMismatch();
        }

        // Google sends a real boolean; older tokens in the wild send the string.
        $verified = $payload->email_verified ?? false;

        if ($verified !== true && $verified !== 'true') {
            throw GoogleIdentityException::emailNotVerified();
        }

        $email = $this->stringClaim($payload, 'email');

        return new GoogleIdentity(
            sub: $this->stringClaim($payload, 'sub'),
            email: $email,
            // `given_name` is absent on some Workspace accounts. The local part of
            // the email is a poor name but it is a real one, and an empty `name`
            // would break the `NOT NULL` on the column.
            firstName: $this->optionalStringClaim($payload, 'given_name')
                ?? Str::before($email, '@'),
            lastName: $this->optionalStringClaim($payload, 'family_name'),
        );
    }

    private function decode(string $credential): object
    {
        $keyId = $this->keyIdOf($credential);

        try {
            return JWT::decode($credential, $this->keySet($keyId));
        } catch (GoogleIdentityException $e) {
            throw $e;
        } catch (Exception $e) {
            // Expired, tampered with, signed by a key that is not Google's — the
            // library does not distinguish them for us and neither should the
            // answer we give.
            //
            // `Exception` y NO `Throwable`: un `Error` —una clase que falta, un
            // tipo que no cierra— es un bug nuestro, no un token falso, y
            // tiene que llegar a Sentry como 500 en vez de convertirse en
            // "no pudimos validar tu cuenta". Ver keyIdOf() para el incidente
            // que lo enseño.
            throw GoogleIdentityException::invalidSignature($e->getMessage());
        }
    }

    /**
     * Reads `kid` out of the header without trusting anything in it.
     *
     * Nothing is decided from this value: it only selects which published key to
     * verify against, and a `kid` naming a key Google never published simply
     * finds no key and fails.
     */
    private function keyIdOf(string $credential): string
    {
        $segments = explode('.', $credential);

        if (count($segments) !== 3) {
            throw GoogleIdentityException::malformedCredential('expected three JWT segments');
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 512, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            // Esto decia `catch (Throwable $e)` y el 2026-08-22 se comio un
            // `Class "Firebase\JWT\JWT" not found` —el paquete no estaba
            // instalado en un contenedor— y lo reporto como 401 "no pudimos
            // validar tu cuenta de Google". El usuario habria reintentado para
            // siempre contra un servidor roto. Un `Error` no es culpa de quien
            // llama y no se le puede contestar como si lo fuera.
            throw GoogleIdentityException::malformedCredential('unreadable header: '.$e->getMessage());
        }

        if (! is_array($header) || ! isset($header['kid']) || ! is_string($header['kid'])) {
            throw GoogleIdentityException::malformedCredential('header carries no `kid`');
        }

        return $header['kid'];
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    private function keySet(string $keyId): array
    {
        $keys = JWK::parseKeySet($this->jwks());

        if (isset($keys[$keyId])) {
            return $keys;
        }

        // Unknown `kid` almost always means Google rotated and our copy is old,
        // not that the token is forged. One forced refetch tells the two apart;
        // if the key is still missing, `JWT::decode()` rejects it below.
        return JWK::parseKeySet($this->jwks(force: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(bool $force = false): array
    {
        if ($force) {
            Cache::forget(self::CACHE_KEY);
        }

        $jwks = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            try {
                // Through OutboundHttp and not `Http::` directly: this is the one
                // outbound call on the login path, and a call that skips that
                // class is a call with no timeout and no retry policy.
                $response = OutboundHttp::to('google_certs')->get(self::JWKS_URL);
            } catch (Exception $e) {
                // Idem: una red caida es una Exception, un bug es un Error, y el
                // segundo no se anuncia como "Google no responde".
                throw GoogleIdentityException::keysUnavailable($e->getMessage());
            }

            if (! $response->successful()) {
                throw GoogleIdentityException::keysUnavailable('HTTP '.$response->status());
            }

            $body = $response->json();

            if (! is_array($body) || ! isset($body['keys']) || ! is_array($body['keys']) || $body['keys'] === []) {
                throw GoogleIdentityException::keysUnavailable('key set is empty or malformed');
            }

            return $body;
        });

        return is_array($jwks) ? $jwks : [];
    }

    private function stringClaim(object $payload, string $claim): string
    {
        $value = $payload->{$claim} ?? null;

        if (! is_string($value) || $value === '') {
            throw GoogleIdentityException::missingClaim($claim);
        }

        return $value;
    }

    private function optionalStringClaim(object $payload, string $claim): ?string
    {
        $value = $payload->{$claim} ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
