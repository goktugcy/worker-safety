<?php

declare(strict_types=1);

namespace Example\After;

/**
 * The same behaviour, expressed as a request-scoped object instead of static
 * state. Nothing here survives the request.
 */
final class UserContext
{
    private ?object $currentUser = null;

    public function login(object $user): void
    {
        $this->currentUser = $user;
    }

    public function current(): ?object
    {
        return $this->currentUser;
    }
}
