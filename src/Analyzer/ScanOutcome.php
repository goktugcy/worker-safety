<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use WorkerSafety\Config\Configuration;
use WorkerSafety\Finding\FindingCollection;
use WorkerSafety\Reporting\ScanReport;
use WorkerSafety\Rule\RuleRegistry;

/**
 * Everything a caller might need after a scan.
 */
final class ScanOutcome
{
    public function __construct(
        public readonly ScanReport $report,
        public readonly FindingCollection $findingsBeforeBaseline,
        public readonly RuleRegistry $registry,
        public readonly Configuration $configuration,
    ) {
    }
}
