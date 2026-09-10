<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

/**
 * A dispatcher created per request: appending to its own instance property
 * proves nothing about cross-request lifetime.
 */
class LocalDispatcher
{
    private array $listeners = [];

    public function add(): void
    {
        $this->listeners[] = fn (): int => 1;
    }
}
