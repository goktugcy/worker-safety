<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use WorkerSafety\Ast\ParseFailure;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Ignore\SuppressionIndex;

/**
 * Outcome of analyzing a single file.
 */
final class FileAnalysisResult
{
    /**
     * @param list<Finding> $findings
     * @param list<ParseFailure> $parseFailures
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $parseFailures,
        public readonly SuppressionIndex $suppressions,
        public readonly bool $analyzed,
    ) {
    }
}
