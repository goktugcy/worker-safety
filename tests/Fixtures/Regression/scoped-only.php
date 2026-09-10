<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

class ScopedOnly
{
    public ?string $user = null;
}

class ScopedBindings
{
    public function register(): void
    {
        $app = null;

        // Registered scoped under its own key: nothing to report.
        $app->scoped(ScopedOnly::class);
    }
}
