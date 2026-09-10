<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS004;

/**
 * An immutable singleton: shared, but nothing can change after construction.
 */
final class Clock
{
    private static ?self $instance = null;

    private function __construct(private readonly \DateTimeZone $timezone)
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(new \DateTimeZone('UTC'));
        }

        return self::$instance;
    }

    public function timezone(): \DateTimeZone
    {
        return $this->timezone;
    }
}
