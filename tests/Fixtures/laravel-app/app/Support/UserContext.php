<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The §33 acceptance case: request-specific mutable state bound as a singleton.
 */
final class UserContext
{
    public ?User $user = null;

    public function forget(): void
    {
        $this->user = null;
    }
}
