<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Ast\ParseFailure;
use WorkerSafety\Finding\FindingCollection;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * Everything the reporters need, and nothing about how it is rendered.
 */
final class ScanReport
{
    /**
     * @param list<ParseFailure> $parseFailures
     * @param list<string> $scannedPaths project-relative
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly DetectedFramework $framework,
        public readonly RuntimeTargetSet $runtimes,
        public readonly FindingCollection $findings,
        public readonly int $filesScanned,
        public readonly array $parseFailures,
        public readonly int $suppressedCount,
        public readonly int $baselineFilteredCount,
        public readonly float $durationSeconds,
        public readonly ?Severity $failOn,
        public readonly ?string $configPath,
        public readonly ?string $baselinePath,
        public readonly array $scannedPaths = [],
        public readonly string $phpVersion = PHP_VERSION,
        public readonly string $toolVersion = ApplicationInfo::VERSION,
        public readonly bool $failOnParseError = true,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->findings->countsBySeverity();
    }

    /**
     * True when at least one file could not be analyzed at all.
     *
     * A scan that skipped files is not evidence of safety, so it is tracked
     * separately from the severity threshold.
     */
    public function isIncomplete(): bool
    {
        return $this->unanalyzedFiles() !== [];
    }

    /**
     * Project-relative paths of the files that produced no usable AST.
     *
     * @return list<string>
     */
    public function unanalyzedFiles(): array
    {
        $paths = [];

        foreach ($this->parseFailures as $failure) {
            if ($failure->fatal) {
                $paths[$failure->relativePath] = true;
            }
        }

        return array_keys($paths);
    }

    public function unanalyzedFileCount(): int
    {
        return count($this->unanalyzedFiles());
    }

    public function failedOnSeverity(): bool
    {
        return $this->failOn instanceof Severity && $this->findings->hasSeverityAtLeast($this->failOn);
    }

    public function failedOnIncompleteAnalysis(): bool
    {
        return $this->failOnParseError && $this->isIncomplete();
    }

    public function failed(): bool
    {
        return $this->failedOnSeverity() || $this->failedOnIncompleteAnalysis();
    }

    public function exitCode(): ExitCode
    {
        return $this->failed() ? ExitCode::FindingsAboveThreshold : ExitCode::Success;
    }

    public function parseFailureCount(): int
    {
        return count($this->parseFailures);
    }

    /**
     * Number of findings at or above the failure threshold.
     */
    public function failingCount(): int
    {
        if (!$this->failOn instanceof Severity) {
            return 0;
        }

        return $this->findings->withSeverityAtLeast($this->failOn)->count();
    }
}
