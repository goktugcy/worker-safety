<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use WorkerSafety\Exception\AnalysisException;
use WorkerSafety\Framework\FrameworkAdapter;
use WorkerSafety\Support\Paths;

/**
 * Decides what to scan.
 *
 * Precedence: CLI arguments, then the configuration file, then the framework
 * adapter's defaults (only those that exist), then the project root.
 */
final class ScanPathResolver
{
    /**
     * @param list<string> $cliPaths
     * @param list<string> $configuredPaths
     *
     * @return list<string> absolute, existing paths
     */
    public function resolve(
        array $cliPaths,
        array $configuredPaths,
        FrameworkAdapter $adapter,
        string $projectRoot,
    ): array {
        $candidates = $cliPaths !== [] ? $cliPaths : $configuredPaths;

        if ($candidates !== []) {
            return $this->absolute($candidates, $projectRoot, false);
        }

        $defaults = $this->absolute($adapter->defaultPaths(), $projectRoot, true);

        if ($defaults !== []) {
            return $defaults;
        }

        if (!is_dir($projectRoot)) {
            throw AnalysisException::pathNotFound($projectRoot);
        }

        return [Paths::normalize($projectRoot)];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function absolute(array $paths, string $projectRoot, bool $skipMissing): array
    {
        $resolved = [];

        foreach ($paths as $path) {
            $absolute = Paths::makeAbsolute($path, $projectRoot);

            if (!file_exists($absolute)) {
                if ($skipMissing) {
                    continue;
                }

                throw AnalysisException::pathNotFound($path);
            }

            if (!in_array($absolute, $resolved, true)) {
                $resolved[] = $absolute;
            }
        }

        return $resolved;
    }
}
