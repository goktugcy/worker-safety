<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

class CaseSensitiveState
{
    public ?string $user = null;
}

class AliasedState
{
    public ?string $user = null;
}

class KeyBindings
{
    public function register(): void
    {
        $app = null;

        // Container keys are plain array keys: 'Shared' is not 'shared', so
        // the scoped registration does not flush this singleton.
        $app->singleton('Shared', CaseSensitiveState::class);
        $app->scoped('shared', CaseSensitiveState::class);

        // Scoping an alias does NOT give the aliased binding a per-request
        // lifetime: forgetScopedInstances() unsets $instances['aliased']
        // without resolving aliases, and bind('aliased', ...) has already
        // deleted $aliases['aliased']. The singleton survives.
        $app->singleton(AliasedState::class);
        $app->alias(AliasedState::class, 'aliased');
        $app->scoped('aliased', AliasedState::class);
    }
}
