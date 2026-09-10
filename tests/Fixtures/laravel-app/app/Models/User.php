<?php

declare(strict_types=1);

namespace App\Models;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
    ) {
    }
}
