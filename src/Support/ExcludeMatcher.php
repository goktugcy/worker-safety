<?php

declare(strict_types=1);

namespace WorkerSafety\Support;

/**
 * Matches project-relative paths against the configured exclude patterns.
 *
 * Supported pattern forms:
 *  - `vendor`            a path segment anywhere in the path
 *  - `app/Legacy`        a path prefix relative to the project root
 *  - `*.blade.php`       an fnmatch glob applied to the full relative path and to the basename
 */
final class ExcludeMatcher
{
    /**
     * @var list<string>
     */
    private readonly array $globs;

    /**
     * @var array<string, true>
     */
    private readonly array $segments;

    /**
     * @var list<string>
     */
    private readonly array $prefixes;

    /**
     * @param list<string> $patterns
     */
    public function __construct(array $patterns)
    {
        $globs = [];
        $segments = [];
        $prefixes = [];

        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern));
            $pattern = trim($pattern, '/');

            if ($pattern === '') {
                continue;
            }

            if (str_contains($pattern, '*') || str_contains($pattern, '?') || str_contains($pattern, '[')) {
                $globs[] = $pattern;

                continue;
            }

            if (str_contains($pattern, '/')) {
                $prefixes[] = $pattern;

                continue;
            }

            $segments[$pattern] = true;
        }

        $this->globs = $globs;
        $this->segments = $segments;
        $this->prefixes = $prefixes;
    }

    /**
     * @param list<string> $patterns
     */
    public static function fromPatterns(array $patterns): self
    {
        return new self($patterns);
    }

    public function matches(string $relativePath): bool
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '') {
            return false;
        }

        if ($this->segments !== []) {
            foreach (explode('/', $relativePath) as $segment) {
                if (isset($this->segments[$segment])) {
                    return true;
                }
            }
        }

        foreach ($this->prefixes as $prefix) {
            if ($relativePath === $prefix || str_starts_with($relativePath, $prefix . '/')) {
                return true;
            }
        }

        if ($this->globs !== []) {
            $basename = basename($relativePath);

            foreach ($this->globs as $glob) {
                if (fnmatch($glob, $relativePath) || fnmatch($glob, $basename)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->globs === [] && $this->segments === [] && $this->prefixes === [];
    }
}
