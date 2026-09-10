<?php

declare(strict_types=1);

namespace WorkerSafety\Framework;

enum Framework: string
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Symfony => 'Symfony',
            self::None => 'plain PHP',
        };
    }
}
