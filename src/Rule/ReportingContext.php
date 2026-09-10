<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use WorkerSafety\Ast\Index\ProjectIndex;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * The subset of analysis state every rule hook can rely on.
 */
interface ReportingContext
{
    public function index(): ProjectIndex;

    public function framework(): DetectedFramework;

    public function runtimes(): RuntimeTargetSet;

    public function projectRoot(): string;
}
