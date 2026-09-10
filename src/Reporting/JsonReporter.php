<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\Severity;

/**
 * Stable machine-readable report.
 *
 * The `version` field is the schema version and is bumped only on a breaking
 * change, so tooling can rely on it independently of the tool version.
 */
final class JsonReporter implements Reporter
{
    public const SCHEMA_VERSION = '1';

    public function report(ScanReport $report, OutputInterface $output): void
    {
        $output->writeln($this->encode($report));
    }

    public function encode(ScanReport $report): string
    {
        return (string) json_encode(
            $this->toArray($report),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(ScanReport $report): array
    {
        $counts = $report->counts();

        return [
            'version' => self::SCHEMA_VERSION,
            'tool' => [
                'name' => ApplicationInfo::NAME,
                'package' => ApplicationInfo::PACKAGE,
                'version' => $report->toolVersion,
            ],
            'project' => [
                'root' => $report->projectRoot,
                'paths' => $report->scannedPaths,
                'framework' => [
                    'name' => $report->framework->identifier(),
                    'version' => $report->framework->version,
                ],
                'runtimes' => $report->runtimes->values(),
                'php' => $report->phpVersion,
                'config' => $report->configPath,
                'baseline' => $report->baselinePath,
            ],
            'summary' => [
                'files' => $report->filesScanned,
                'duration_seconds' => round($report->durationSeconds, 4),
                'total' => $report->findings->count(),
                'critical' => $counts[Severity::Critical->value],
                'high' => $counts[Severity::High->value],
                'medium' => $counts[Severity::Medium->value],
                'low' => $counts[Severity::Low->value],
                'info' => $counts[Severity::Info->value],
                'suppressed' => $report->suppressedCount,
                'baseline_filtered' => $report->baselineFilteredCount,
                'parse_errors' => $report->parseFailureCount(),
                'files_not_analyzed' => $report->unanalyzedFileCount(),
                'incomplete' => $report->isIncomplete(),
                'fail_on' => $report->failOn?->value,
                'fail_on_parse_error' => $report->failOnParseError,
                'failed' => $report->failed(),
                'failed_on_severity' => $report->failedOnSeverity(),
                'failed_on_incomplete_analysis' => $report->failedOnIncompleteAnalysis(),
                'by_rule' => $report->findings->countsByRule(),
            ],
            'findings' => array_map(
                fn (Finding $finding): array => $this->finding($finding),
                $report->findings->toArray(),
            ),
            'parse_errors' => array_map(
                static fn (\WorkerSafety\Ast\ParseFailure $failure): array => [
                    'file' => $failure->relativePath,
                    'line' => $failure->line,
                    'message' => $failure->message,
                    'skipped' => $failure->fatal,
                ],
                $report->parseFailures,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(Finding $finding): array
    {
        return [
            'rule' => $finding->ruleId,
            'title' => $finding->title,
            'severity' => $finding->severity->value,
            'category' => $finding->category->value,
            'file' => $finding->location->relativePath,
            'line' => $finding->location->line,
            'column' => $finding->location->column,
            'end_line' => $finding->location->endLine,
            'message' => $finding->message,
            'details' => $finding->details,
            'remediation' => $finding->remediation,
            'snippet' => $finding->snippet,
            'symbol' => $finding->symbol->toArray(),
            'runtimes' => $finding->runtimes->values(),
            'framework' => $finding->framework,
            'fingerprint' => $finding->fingerprint(),
        ];
    }
}
