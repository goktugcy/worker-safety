<?php

declare(strict_types=1);

namespace WorkerSafety\Runtime;

use WorkerSafety\Exception\ConfigurationException;

/**
 * A persistent PHP worker runtime the analysis can target.
 */
enum RuntimeTarget: string
{
    case FrankenPhp = 'frankenphp';
    case Octane = 'octane';
    case RoadRunner = 'roadrunner';
    case Swoole = 'swoole';

    public function label(): string
    {
        return match ($this) {
            self::FrankenPhp => 'FrankenPHP',
            self::Octane => 'Octane',
            self::RoadRunner => 'RoadRunner',
            self::Swoole => 'Swoole',
        };
    }

    /**
     * One short runtime specific remark, shown when a single runtime is targeted.
     */
    public function note(): string
    {
        return match ($this) {
            self::FrankenPhp => 'FrankenPHP worker mode keeps the same PHP process (and therefore every static, global and singleton) alive across the whole worker loop.',
            self::Octane => 'Laravel Octane resets framework container state between requests, but application-owned statics and globals are never flushed unless you list them in octane.flush or reset them yourself.',
            self::RoadRunner => 'RoadRunner reuses the PHP worker process for many requests; only a worker restart (max_jobs) clears retained state.',
            self::Swoole => 'Swoole runs requests in coroutines inside a shared process, so retained state is visible across requests and may be read concurrently.',
        };
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        $normalized = match ($normalized) {
            'franken-php', 'franken_php', 'frankenphp' => 'frankenphp',
            'road-runner', 'road_runner', 'roadrunner', 'rr' => 'roadrunner',
            'laravel-octane', 'octane' => 'octane',
            'openswoole', 'open-swoole', 'swoole' => 'swoole',
            default => $normalized,
        };

        $runtime = self::tryFrom($normalized);

        if (!$runtime instanceof self) {
            throw ConfigurationException::invalidValue(
                'runtime',
                sprintf('"%s" is not a known runtime (%s, all).', $value, implode(', ', self::names())),
            );
        }

        return $runtime;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
