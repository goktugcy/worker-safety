<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS009;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

final class ReportController
{
    private static array $listeners = [];

    public function show(string $id): void
    {
        Event::listen('report.viewed', function () use ($id): void {
            // Captures $id, and keeps firing for every later request.
        });

        Str::macro('slugify', fn (string $value): string => strtolower($value));

        self::$listeners[] = fn () => null;

        spl_autoload_register(static fn (string $class): bool => false);
    }
}
