<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * A channel whose reply() always blows up.
 *
 * Used to prove that a job's failed() handler swallows a delivery failure. If it
 * propagated, the queue would treat failure handling as a new failure and the user
 * would be no better informed.
 */
final class ThrowingCaptureChannel extends RecordingCaptureChannel
{
    public function reply(string $externalId, string $text): void
    {
        throw new RuntimeException('el canal esta caido');
    }
}
