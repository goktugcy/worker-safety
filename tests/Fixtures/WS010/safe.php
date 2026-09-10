<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS010;

final class ResponseWriter
{
    public function write(string $body): string
    {
        return $body;
    }

    public function isCli(): bool
    {
        // Comparing against a persistent-friendly SAPI is not an FPM assumption.
        return PHP_SAPI === 'cli';
    }
}
