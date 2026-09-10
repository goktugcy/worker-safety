<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS004;

/**
 * A plain service with no singleton plumbing: nothing to report.
 */
final class Formatter
{
    public function __construct(private readonly string $pattern = 'Y-m-d')
    {
    }

    public function format(\DateTimeImmutable $moment): string
    {
        return $moment->format($this->pattern);
    }
}
