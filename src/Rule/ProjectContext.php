<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use WorkerSafety\Ast\Index\ProjectIndex;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * Analysis state available once every file has been visited.
 */
final class ProjectContext implements ReportingContext
{
    public function __construct(
        private readonly ProjectIndex $index,
        private readonly DetectedFramework $framework,
        private readonly RuntimeTargetSet $runtimes,
        private readonly string $projectRoot,
    ) {
    }

    public function index(): ProjectIndex
    {
        return $this->index;
    }

    public function framework(): DetectedFramework
    {
        return $this->framework;
    }

    public function runtimes(): RuntimeTargetSet
    {
        return $this->runtimes;
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }
}
