<?php

declare(strict_types=1);

namespace Example\After;

final class Config
{
    public function __construct(
        public readonly string $currency,
    ) {
    }
}
