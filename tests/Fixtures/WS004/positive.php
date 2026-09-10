<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS004;

final class Session
{
    private static ?self $instance = null;

    public ?string $userId = null;

    private array $attributes = [];

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function set(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
    }
}
