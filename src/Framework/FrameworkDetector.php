<?php

declare(strict_types=1);

namespace WorkerSafety\Framework;

/**
 * Detects the framework from Composer metadata only.
 *
 * The analyzed application is never bootstrapped or autoloaded: detection is a
 * pure read of composer.json and composer.lock.
 */
final class FrameworkDetector
{
    /**
     * Package name => framework, most specific first.
     *
     * @var array<string, Framework>
     */
    private const SIGNATURES = [
        'laravel/framework' => Framework::Laravel,
        'laravel/lumen-framework' => Framework::Laravel,
        'illuminate/support' => Framework::Laravel,
        'symfony/framework-bundle' => Framework::Symfony,
        'symfony/symfony' => Framework::Symfony,
    ];

    public function detect(string $projectRoot): DetectedFramework
    {
        $composer = $this->readJson($projectRoot . '/composer.json');

        if ($composer === null) {
            return DetectedFramework::none();
        }

        $requirements = $this->requirements($composer);
        $lockVersions = $this->lockVersions($projectRoot . '/composer.lock');

        foreach (self::SIGNATURES as $package => $framework) {
            if (!array_key_exists($package, $requirements)) {
                continue;
            }

            $version = $lockVersions[$package] ?? $requirements[$package];

            return new DetectedFramework($framework, $this->majorVersion($version), $package);
        }

        return DetectedFramework::none();
    }

    /**
     * @param array<string, mixed> $composer
     *
     * @return array<string, string>
     */
    private function requirements(array $composer): array
    {
        $requirements = [];

        foreach (['require', 'require-dev'] as $section) {
            $packages = $composer[$section] ?? null;

            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $name => $constraint) {
                if (is_string($name) && is_string($constraint)) {
                    $requirements[strtolower($name)] = $constraint;
                }
            }
        }

        return $requirements;
    }

    /**
     * @return array<string, string>
     */
    private function lockVersions(string $lockPath): array
    {
        $lock = $this->readJson($lockPath);

        if ($lock === null) {
            return [];
        }

        $versions = [];

        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock[$section] ?? null;

            if (!is_array($packages)) {
                continue;
            }

            foreach ($packages as $package) {
                if (!is_array($package)) {
                    continue;
                }

                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;

                if (is_string($name) && is_string($version)) {
                    $versions[strtolower($name)] = $version;
                }
            }
        }

        return $versions;
    }

    /**
     * Reduce `v12.20.1` or `^12.0` to `12`.
     */
    private function majorVersion(string $version): ?string
    {
        if (preg_match('/(\d+)/', $version, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $result = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
