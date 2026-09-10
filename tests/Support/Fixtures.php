<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Support;

use WorkerSafety\Support\Paths;

/**
 * Locates the fixture files.
 */
final class Fixtures
{
    private function __construct()
    {
    }

    public static function root(): string
    {
        return Paths::normalize(dirname(__DIR__) . '/Fixtures');
    }

    public static function path(string $relative): string
    {
        return Paths::normalize(self::root() . '/' . ltrim($relative, '/'));
    }

    /**
     * The Laravel-like fixture project used by the integration tests.
     */
    public static function laravelApp(): string
    {
        return self::path('laravel-app');
    }
}
