<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

class RequestContext
{
    public ?string $user = null;
}

class SafeDep
{
}

class Bindings
{
    public function register(): void
    {
        $app = null;

        // A scoped binding under a *different* key does not flush this one.
        $app->singleton('shared', RequestContext::class);
        $app->scoped('request', RequestContext::class);

        // A free-form service id is still a binding.
        $app->singleton('auth.context', RequestContext::class);

        // An already-built instance names its own concrete type.
        $app->instance('ctx', new RequestContext());

        // The *returned* type is the service, not the first allocation.
        $app->singleton(RequestContext::class, function () {
            $dep = new SafeDep();

            return new RequestContext();
        });
    }
}
