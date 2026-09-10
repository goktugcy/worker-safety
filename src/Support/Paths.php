<?php

declare(strict_types=1);

namespace WorkerSafety\Support;

/**
 * Pure path helpers. Nothing here touches the filesystem except realpath().
 */
final class Paths
{
    private function __construct()
    {
    }

    /**
     * Normalize separators and collapse `.` / `..` segments without resolving symlinks.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $isAbsolute = str_starts_with($path, '/');
        $prefix = '';

        if (preg_match('#^([a-zA-Z]:)/#', $path, $matches) === 1) {
            $prefix = $matches[1];
            $path = substr($path, strlen($prefix));
            $isAbsolute = true;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);

                    continue;
                }

                if ($isAbsolute) {
                    continue;
                }
            }

            $segments[] = $segment;
        }

        $normalized = implode('/', $segments);

        if ($isAbsolute) {
            return $prefix . '/' . $normalized;
        }

        return $normalized === '' ? '.' : $normalized;
    }

    public static function isAbsolute(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, '/') || preg_match('#^[a-zA-Z]:/#', $path) === 1;
    }

    public static function makeAbsolute(string $path, string $workingDirectory): string
    {
        if (self::isAbsolute($path)) {
            return self::normalize($path);
        }

        return self::normalize($workingDirectory . '/' . $path);
    }

    /**
     * Express $path relative to $base. Falls back to the absolute path when the
     * two live on different roots.
     */
    public static function makeRelative(string $path, string $base): string
    {
        $path = self::normalize($path);
        $base = rtrim(self::normalize($base), '/');

        if ($base === '' || $base === '/') {
            return ltrim($path, '/');
        }

        if ($path === $base) {
            return '.';
        }

        if (str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base) + 1);
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', self::normalize($path)), static fn (string $s): bool => $s !== ''));
    }
}
