<?php

declare(strict_types=1);

namespace App\Services\Capture;

use InvalidArgumentException;

/**
 * Resolves a capture channel by its key.
 *
 * Exists so callers never grow a `match` statement over channel names: adding an
 * adapter is a registration, not an edit to every consumer.
 */
class CaptureChannelRegistry
{
    /** @var array<string, CaptureChannel> */
    private array $channels = [];

    /** @param iterable<CaptureChannel> $channels */
    public function __construct(iterable $channels = [])
    {
        foreach ($channels as $channel) {
            $this->channels[$channel->key()] = $channel;
        }
    }

    public function for(string $key): CaptureChannel
    {
        return $this->channels[$key]
            ?? throw new InvalidArgumentException("No capture channel is registered for key [{$key}].");
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->channels);
    }
}
