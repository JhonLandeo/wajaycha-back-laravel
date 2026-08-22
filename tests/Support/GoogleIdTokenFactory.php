<?php

declare(strict_types=1);

namespace Tests\Support;

use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Mints ID tokens the way Google mints them, signed with a throwaway key pair.
 *
 * **Why real signatures.** The alternative was mocking
 * {@see \App\Services\Auth\GoogleIdTokenVerifier} and asserting on whatever it
 * was told to return — which tests that a stub returns its stub. The verifier's
 * entire job is deciding whether a signature is real; a suite that never puts a
 * real signature in front of it asserts nothing about the only thing that
 * matters. Here the tokens are genuinely signed, the fake JWKS genuinely carries
 * the matching public key, and {@see forgedToken()} genuinely fails to verify.
 *
 * **Why the keys are committed instead of generated.** The first version called
 * `openssl_pkey_new()` per run. It fails on any PHP whose OpenSSL cannot find
 * its `openssl.cnf` — which is the case on the machine this was written on — and
 * a test that passes only on some machines is worse than no test: it turns a red
 * suite into a question about the runner. Fixed keys are also deterministic and
 * free.
 *
 * These two PEMs are test fixtures and nothing else. They sign nothing real,
 * they are not accepted by anything outside this suite, and Google has never
 * heard of them. Do not reuse them anywhere.
 */
final class GoogleIdTokenFactory
{
    public const KID = 'wajaycha-test-key';

    public const CLIENT_ID = '1234567890-test.apps.googleusercontent.com';

    /**
     * The public half of `google-signing-key.pem`, as Google would publish it.
     *
     * Hardcoded rather than derived from the PEM at runtime so the test states
     * what it expects instead of restating what the code does — if the key file
     * is ever swapped, these stop matching and the suite says so.
     */
    private const MODULUS = 'i9-mWntwt6FWRtnmfreTdL1khDqUD2Uq9Rx-GtY_XIlUw4nK5Tf5TIS3HvkVsTWmDclJcOJVMll6KEf-f20MarLLdXgTZFV-8y1qNlr_kMLF_qva5AA00PdYsVLHhE22GK3FJFxqhdBlM2tJ7ltrtk-pezXBYFeW--meHO0jeUlcJC1oDBE7tUAXVTQ2ewdSU3V9KILBXxUban4B8Jg15vviXNoEBZAW-c3w2W1VdPYtCi-MnUjuIjxUP62KlccwbcOfZoTM0TJhWVYHA-x8CD0SKwmPS_V0Kqt0CMHsJRRk931SiJ72FKdumZOqhvcDLa2zbW0ONP4jZRN3uMdYvw';

    private const EXPONENT = 'AQAB';

    /**
     * The JWKS document Google would serve, carrying only our test key.
     *
     * @return array{keys: array<int, array<string, string>>}
     */
    public static function jwks(): array
    {
        return [
            'keys' => [[
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => self::KID,
                'n' => self::MODULUS,
                'e' => self::EXPONENT,
            ]],
        ];
    }

    /**
     * A well-formed, correctly signed token. Pass `$overrides` to break exactly
     * one claim at a time; a value of `null` removes the claim entirely.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function token(array $overrides = [], ?string $kid = null): string
    {
        return JWT::encode(
            self::claims($overrides),
            self::key('google-signing-key.pem'),
            'RS256',
            $kid ?? self::KID,
        );
    }

    /**
     * A token that is perfect in every way except that Google did not sign it.
     *
     * The payload is identical to a real one on purpose: an attacker gets to
     * write whatever payload they like, so the payload is never what makes a
     * token trustworthy. Only the key differs, and only the key should matter.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function forgedToken(array $overrides = []): string
    {
        return JWT::encode(
            self::claims($overrides),
            self::key('foreign-signing-key.pem'),
            'RS256',
            self::KID,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function claims(array $overrides): array
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => '104729183746152938471',
            'email' => 'ana@example.test',
            'email_verified' => true,
            'given_name' => 'Ana',
            'family_name' => 'Quispe',
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ], $overrides);

        // `null` means "this claim was absent", which is a different test from
        // "this claim was empty". This keeps `false`, which matters for
        // `email_verified`.
        return array_filter($claims, static fn (mixed $value): bool => $value !== null);
    }

    private static function key(string $file): string
    {
        $path = __DIR__.'/../Fixtures/auth/'.$file;
        $pem = file_get_contents($path);

        if ($pem === false) {
            throw new RuntimeException("No se pudo leer la clave de prueba: {$path}");
        }

        return $pem;
    }
}
