<?php

declare(strict_types=1);

// Bootstrap-level registration runs once per worker boot: reported, but low.
spl_autoload_register(static fn (string $class): bool => false);
