<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * CLI-level overrides for a scan.
 *
 * Every override is nullable so that "not given on the command line" stays
 * distinguishable from "explicitly set to the default".
 */
final class ScanOptions
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly array $paths = [],
        public readonly ?string $configPath = null,
        public readonly ?RuntimeTargetSet $runtimes = null,
        public readonly ?string $failOn = null,
        public readonly bool $ignoreBaseline = false,
        public readonly ?string $baselinePath = null,
    ) {
    }
}
