<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS010;

/**
 * A console entry point is allowed to terminate the process.
 */
final class ExportCommand
{
    public function handle(): never
    {
        exit(1);
    }
}
