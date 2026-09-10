<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\ParseError;

final class Broken
{
    public static ?object $leaked = null;

    public static function oops(): void
    {
        self::$leaked = new \stdClass()
    }
}
