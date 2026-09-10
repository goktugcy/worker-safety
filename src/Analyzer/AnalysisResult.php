<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use WorkerSafety\Ast\Index\ProjectIndex;
use WorkerSafety\Ast\ParseFailure;
use WorkerSafety\Finding\FindingCollection;

/**
 * Outcome of an analysis run.
 *
 * Produced by the static analyzer today; a runtime analyzer would produce the
 * same object, which is what keeps the reporting layer reusable.
 */
final class AnalysisResult
{
    /**
     * @param list<ParseFailure> $parseFailures
     */
    public function __construct(
        public readonly FindingCollection $findings,
        public readonly int $filesScanned,
        public readonly array $parseFailures,
        public readonly int $suppressedCount,
        public readonly ProjectIndex $index,
        public readonly float $durationSeconds,
    ) {
    }

    public function parseFailureCount(): int
    {
        return count($this->parseFailures);
    }
}
