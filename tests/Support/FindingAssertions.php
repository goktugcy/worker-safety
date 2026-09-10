<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Support;

use WorkerSafety\Analyzer\AnalysisResult;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\FindingCollection;
use WorkerSafety\Finding\Severity;

/**
 * Assertions shared by the rule tests.
 */
trait FindingAssertions
{
    protected static function collection(AnalysisResult|FindingCollection $subject): FindingCollection
    {
        return $subject instanceof AnalysisResult ? $subject->findings : $subject;
    }

    /**
     * @return list<Finding>
     */
    protected static function findingsFor(
        AnalysisResult|FindingCollection $subject,
        string $ruleId,
        ?string $fileSuffix = null,
    ): array {
        return array_values(array_filter(
            self::collection($subject)->toArray(),
            static function (Finding $finding) use ($ruleId, $fileSuffix): bool {
                if ($finding->ruleId !== $ruleId) {
                    return false;
                }

                return $fileSuffix === null || str_ends_with($finding->location->relativePath, $fileSuffix);
            },
        ));
    }

    protected function assertHasFinding(
        AnalysisResult|FindingCollection $subject,
        string $ruleId,
        ?int $line = null,
        ?Severity $severity = null,
        ?string $fileSuffix = null,
    ): Finding {
        $matches = self::findingsFor($subject, $ruleId, $fileSuffix);

        self::assertNotEmpty($matches, sprintf(
            'Expected a %s finding%s, got: %s',
            $ruleId,
            $fileSuffix === null ? '' : ' in ' . $fileSuffix,
            self::describe($subject),
        ));

        if ($line !== null) {
            $onLine = array_values(array_filter(
                $matches,
                static fn (Finding $finding): bool => $finding->location->line === $line,
            ));

            self::assertNotEmpty($onLine, sprintf(
                'Expected %s on line %d, got lines: %s',
                $ruleId,
                $line,
                implode(', ', array_map(static fn (Finding $f): string => (string) $f->location->line, $matches)),
            ));

            $matches = $onLine;
        }

        if ($severity !== null) {
            $withSeverity = array_values(array_filter(
                $matches,
                static fn (Finding $finding): bool => $finding->severity === $severity,
            ));

            self::assertNotEmpty($withSeverity, sprintf(
                'Expected %s at severity %s, got: %s',
                $ruleId,
                $severity->value,
                implode(', ', array_map(static fn (Finding $f): string => $f->severity->value, $matches)),
            ));

            $matches = $withSeverity;
        }

        return $matches[0];
    }

    protected function assertNoFinding(
        AnalysisResult|FindingCollection $subject,
        string $ruleId,
        ?string $fileSuffix = null,
    ): void {
        $matches = array_map(
            static fn (Finding $finding): string => sprintf(
                '%s %s:%d',
                $finding->ruleId,
                $finding->location->relativePath,
                $finding->location->line,
            ),
            self::findingsFor($subject, $ruleId, $fileSuffix),
        );

        self::assertSame([], $matches, sprintf(
            'Expected no %s finding%s.',
            $ruleId,
            $fileSuffix === null ? '' : ' in ' . $fileSuffix,
        ));
    }

    protected function assertNoFindings(AnalysisResult|FindingCollection $subject, ?string $fileSuffix = null): void
    {
        $findings = self::collection($subject)->toArray();

        if ($fileSuffix !== null) {
            $findings = array_values(array_filter(
                $findings,
                static fn (Finding $finding): bool => str_ends_with($finding->location->relativePath, $fileSuffix),
            ));
        }

        self::assertSame([], array_map(
            static fn (Finding $finding): string => sprintf(
                '%s %s:%d',
                $finding->ruleId,
                $finding->location->relativePath,
                $finding->location->line,
            ),
            $findings,
        ), 'Expected a clean result.');
    }

    /**
     * @return list<string>
     */
    protected static function ruleIds(AnalysisResult|FindingCollection $subject): array
    {
        $ids = array_map(
            static fn (Finding $finding): string => $finding->ruleId,
            self::collection($subject)->toArray(),
        );

        $unique = array_values(array_unique($ids));
        sort($unique);

        return $unique;
    }

    private static function describe(AnalysisResult|FindingCollection $subject): string
    {
        $findings = self::collection($subject)->toArray();

        if ($findings === []) {
            return '(no findings)';
        }

        return implode(', ', array_map(
            static fn (Finding $finding): string => sprintf(
                '%s@%s:%d(%s)',
                $finding->ruleId,
                basename($finding->location->relativePath),
                $finding->location->line,
                $finding->severity->value,
            ),
            $findings,
        ));
    }
}
