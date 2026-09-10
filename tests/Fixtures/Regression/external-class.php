<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression\Local;

class Context
{
    public ?string $user = null;
}

class ExternalBindings
{
    public function register(): void
    {
        $app = null;

        // A qualified name that is not declared here must not be matched
        // against the local class of the same short name.
        $app->singleton(\WorkerSafety\Tests\Fixtures\Regression\External\Context::class);
    }
}
