<?php

declare(strict_types=1);

namespace Example\Before;

use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One RequestState for the whole worker: every request writes into the
        // same object.
        $this->app->singleton(RequestState::class);
    }
}
