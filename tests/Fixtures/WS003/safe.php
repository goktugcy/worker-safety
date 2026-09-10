<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS003;

final class EnvironmentReader
{
    public function tenant(): ?string
    {
        $fromEnv = getenv('TENANT');

        if ($fromEnv !== false) {
            return $fromEnv;
        }

        return isset($_ENV['TENANT']) ? (string) $_ENV['TENANT'] : null;
    }

    public function memoryLimit(): string
    {
        return (string) ini_get('memory_limit');
    }

    public function encoding(): string
    {
        return (string) mb_internal_encoding();
    }

    /**
     * A zero locale argument queries the current setting without changing it.
     */
    public function locale(): string
    {
        return (string) setlocale(LC_ALL, 0);
    }
}
