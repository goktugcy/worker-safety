<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

/**
 * PHP 8.2 readonly class: every property, promoted or not, is immutable.
 */
readonly class Options
{
    public string $mode;

    public function __construct(public string $format = 'Y-m-d')
    {
        $this->mode = 'strict';
    }
}

class OptionBindings
{
    public function register(): void
    {
        $app = null;
        $app->singleton(Options::class);
    }
}
