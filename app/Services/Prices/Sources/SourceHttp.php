<?php

declare(strict_types=1);

namespace App\Services\Prices\Sources;

use App\Support\OutboundHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * The shared request recipe of the adapters: the profile's budget through
 * {@see OutboundHttp}, the identifying User-Agent, and a thrown
 * {@see SourceRequestFailed} on any non-2xx so the ingest action records a
 * failed run. No adapter evades a block: a 403 or a 429 is a failure, not a
 * reason to try another way in.
 */
final class SourceHttp
{
    public function get(string $source, string $profile, string $url): string
    {
        $response = $this->request($profile)->get($url);

        if (! $response->successful()) {
            throw SourceRequestFailed::status($source, $response->status(), $url);
        }

        return $response->body();
    }

    /**
     * @param  array<string, string>  $form
     */
    public function postForm(string $source, string $profile, string $url, array $form): string
    {
        $response = $this->request($profile)->asForm()->post($url, $form);

        if (! $response->successful()) {
            throw SourceRequestFailed::status($source, $response->status(), $url);
        }

        return $response->body();
    }

    private function request(string $profile): PendingRequest
    {
        return OutboundHttp::to($profile)->withUserAgent((string) config('prices.user_agent'));
    }
}
