<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;

/**
 * The shipped trust anchor for EMMSA (spec "Layer 2": TLS verified with a CA
 * bundle for the profile, never disabled). The server sends only its leaf
 * certificate, so the bundle holds the six Let's Encrypt intermediates the leaf
 * can be issued by (YE1-3, YR1-3). The leaf renews about every 90 days and may
 * come from any of them, hence all six.
 */
function emmsaBundleCertificates(): array
{
    $pem = (string) file_get_contents((string) config('http.profiles.emmsa.ca_bundle'));
    preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $matches);

    return array_map(fn (string $cert): array => (array) openssl_x509_parse($cert), $matches[0]);
}

it('points the emmsa profile at the bundle that ships with the app', function () {
    $path = (string) config('http.profiles.emmsa.ca_bundle');

    expect($path)->toBe(resource_path('certs/emmsa-chain.pem'))
        ->and(is_file($path))->toBeTrue()
        ->and(config('http.profiles.emmsa'))->not->toHaveKey('verify');
});

it('bundles exactly the six Let\'s Encrypt YE and YR intermediates, all still valid', function () {
    $certs = emmsaBundleCertificates();
    $names = array_map(fn (array $c): string => (string) $c['subject']['CN'], $certs);
    sort($names);

    expect($names)->toBe(['YE1', 'YE2', 'YE3', 'YR1', 'YR2', 'YR3']);

    foreach ($certs as $cert) {
        expect($cert['subject']['O'])->toBe("Let's Encrypt")
            ->and(CarbonImmutable::createFromTimestamp($cert['validTo_time_t'])->isFuture())->toBeTrue()
            // Intermediates, not trust roots: the issuer is a different name.
            ->and($cert['issuer']['CN'])->not->toBe($cert['subject']['CN']);
    }
});
