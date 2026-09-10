<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use WorkerSafety\Ast\Index\ContainerBindingCollector;
use WorkerSafety\Config\Configuration;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Rule\Rule;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * Everything an analyzer needs for one run.
 */
final class AnalysisRequest
{
    /**
     * @param list<string> $files absolute paths
     * @param list<Rule> $rules
     * @param list<ContainerBindingCollector> $bindingCollectors
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly array $files,
        public readonly Configuration $configuration,
        public readonly DetectedFramework $framework,
        public readonly RuntimeTargetSet $runtimes,
        public readonly array $rules,
        public readonly array $bindingCollectors = [],
    ) {
    }
}
