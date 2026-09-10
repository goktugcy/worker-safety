<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Ast\ParseFailure;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\Severity;

/**
 * Human readable report.
 */
final class ConsoleReporter implements Reporter
{
    private const WIDTH = 76;

    private const DIVIDER = '──────────────────────────────────────────────────────────────────────';

    public function report(ScanReport $report, OutputInterface $output): void
    {
        ConsoleStyles::register($output);

        $this->writeHeader($report, $output);

        if ($report->findings->isEmpty()) {
            $this->writeNoFindings($report, $output);
        } else {
            $this->writeFindings($report, $output);
        }

        $this->writeParseFailures($report, $output);
        $this->writeSummary($report, $output);
    }

    private function writeHeader(ScanReport $report, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln(sprintf('<ws-heading>%s %s</ws-heading>', ApplicationInfo::NAME, $report->toolVersion));
        $output->writeln('');

        $output->writeln('<ws-heading>Project</ws-heading>');
        $output->writeln('  ' . self::escape($report->projectRoot));
        $output->writeln('');

        $output->writeln('<ws-heading>Environment</ws-heading>');
        $output->writeln(sprintf('  PHP        %s', $report->phpVersion));
        $output->writeln(sprintf('  Framework  %s', $report->framework->describe()));
        $output->writeln(sprintf('  Runtime    %s', $report->runtimes->describe()));

        if ($report->configPath !== null) {
            $output->writeln(sprintf('  Config     %s', self::escape($report->configPath)));
        }

        if ($report->baselinePath !== null) {
            $output->writeln(sprintf('  Baseline   %s', self::escape($report->baselinePath)));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '%d PHP %s analyzed in %.2fs.',
            $report->filesScanned,
            $report->filesScanned === 1 ? 'file' : 'files',
            $report->durationSeconds,
        ));
        $output->writeln('');
    }

    private function writeNoFindings(ScanReport $report, OutputInterface $output): void
    {
        $output->writeln('<ws-pass>No worker-safety risks found.</ws-pass>');

        if ($report->suppressedCount > 0 || $report->baselineFilteredCount > 0) {
            $output->writeln('');
            $output->writeln('<ws-muted>Some findings were filtered; see the summary below.</ws-muted>');
        }

        $output->writeln('');
    }

    private function writeFindings(ScanReport $report, OutputInterface $output): void
    {
        $output->writeln('<ws-heading>Findings</ws-heading>');
        $output->writeln('<ws-muted>' . self::DIVIDER . '</ws-muted>');

        foreach ($report->findings as $finding) {
            $this->writeFinding($finding, $report, $output);
            $output->writeln('<ws-muted>' . self::DIVIDER . '</ws-muted>');
        }

        $output->writeln('');
    }

    private function writeFinding(Finding $finding, ScanReport $report, OutputInterface $output): void
    {
        $style = $finding->severity->consoleStyle();

        $output->writeln('');
        $output->writeln(sprintf(
            '<%s>%s</%s> <ws-rule>%s</ws-rule>  %s',
            $style,
            $finding->severity->label(),
            $style,
            $finding->ruleId,
            $finding->title,
        ));

        $output->writeln('');
        $output->writeln($this->formatLocation($finding));

        if ($finding->snippet !== null) {
            $output->writeln('');
            $output->writeln('    <ws-code>' . self::escape($finding->snippet) . '</ws-code>');
        }

        $output->writeln('');

        foreach ($this->wrap($finding->message) as $line) {
            $output->writeln(self::escape($line));
        }

        if ($finding->details !== null) {
            $output->writeln('');

            foreach ($this->wrap($finding->details) as $line) {
                $output->writeln('<ws-muted>' . self::escape($line) . '</ws-muted>');
            }
        }

        if ($finding->runtimes->count() > 0) {
            $output->writeln('');
            $output->writeln('Affected runtimes:');
            $output->writeln('  ' . implode(', ', $finding->runtimes->labels()));

            if ($finding->runtimes->count() === 1) {
                $note = $finding->runtimes->toArray()[0]->note();

                foreach ($this->wrap($note, '  ') as $line) {
                    $output->writeln('<ws-muted>' . self::escape($line) . '</ws-muted>');
                }
            }
        }

        if ($finding->remediation !== []) {
            $output->writeln('');
            $output->writeln('Recommendation:');

            foreach ($finding->remediation as $suggestion) {
                $lines = $this->wrap($suggestion, '    ');
                $first = array_shift($lines);
                $output->writeln('  - ' . self::escape(ltrim((string) $first)));

                foreach ($lines as $line) {
                    $output->writeln(self::escape($line));
                }
            }
        }

        $output->writeln('');
    }

    private function formatLocation(Finding $finding): string
    {
        $location = $finding->location->relativePath . ':' . $finding->location->line;
        $symbol = $finding->symbol->describe();

        if ($symbol === null) {
            return self::escape($location);
        }

        return self::escape($location) . '  <ws-muted>' . self::escape($symbol) . '</ws-muted>';
    }

    private function writeParseFailures(ScanReport $report, OutputInterface $output): void
    {
        if ($report->parseFailures === []) {
            return;
        }

        $output->writeln('<ws-heading>Parse warnings</ws-heading>');

        $shown = array_slice($report->parseFailures, 0, 10);

        foreach ($shown as $failure) {
            $output->writeln(sprintf(
                '  <ws-medium>%s</ws-medium> %s:%d  %s',
                $failure->fatal ? 'SKIPPED' : 'PARTIAL',
                self::escape($failure->relativePath),
                $failure->line,
                self::escape($failure->message),
            ));
        }

        $remaining = count($report->parseFailures) - count($shown);

        if ($remaining > 0) {
            $output->writeln(sprintf('  <ws-muted>… and %d more.</ws-muted>', $remaining));
        }

        $output->writeln('');
    }

    private function writeSummary(ScanReport $report, OutputInterface $output): void
    {
        $output->writeln('<ws-heading>Summary</ws-heading>');
        $output->writeln('');

        foreach (Severity::ordered() as $severity) {
            $count = $report->counts()[$severity->value];
            $label = ucfirst($severity->value);

            $output->writeln(sprintf(
                '  %-9s %s',
                $label,
                $count === 0
                    ? '<ws-muted>0</ws-muted>'
                    : sprintf('<%s>%d</%s>', $severity->consoleStyle(), $count, $severity->consoleStyle()),
            ));
        }

        $output->writeln('');

        if ($report->suppressedCount > 0) {
            $output->writeln(sprintf(
                '  <ws-muted>%d finding(s) suppressed by inline directives or config.</ws-muted>',
                $report->suppressedCount,
            ));
        }

        if ($report->baselineFilteredCount > 0) {
            $output->writeln(sprintf(
                '  <ws-muted>%d finding(s) ignored by the baseline.</ws-muted>',
                $report->baselineFilteredCount,
            ));
        }

        if ($report->parseFailures !== []) {
            $output->writeln(sprintf(
                '  <ws-muted>%d file(s) could not be fully parsed.</ws-muted>',
                $this->countFailedFiles($report->parseFailures),
            ));
        }

        $output->writeln('');

        if ($report->failed()) {
            $output->writeln('<ws-fail>Result: FAILED</ws-fail>');
            $output->writeln('');
            $output->writeln(sprintf(
                '%d finding(s) at or above %s.',
                $report->failingCount(),
                strtoupper(($report->failOn ?? Severity::High)->value),
            ));
        } else {
            $output->writeln('<ws-pass>Result: PASSED</ws-pass>');

            if ($report->failOn === null) {
                $output->writeln('');
                $output->writeln('<ws-muted>No failure threshold configured (fail_on: never).</ws-muted>');
            }
        }

        $output->writeln('');
    }

    /**
     * @param list<ParseFailure> $failures
     */
    private function countFailedFiles(array $failures): int
    {
        $paths = [];

        foreach ($failures as $failure) {
            $paths[$failure->relativePath] = true;
        }

        return count($paths);
    }

    /**
     * Console output is written through the Symfony formatter, so any text
     * coming from the analyzed source has to be escaped or a `<` in the code
     * snippet would be parsed as a style tag.
     */
    private static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, string $indent = ''): array
    {
        $wrapped = wordwrap($text, self::WIDTH - strlen($indent), "\n", false);

        return array_map(
            static fn (string $line): string => $indent . $line,
            explode("\n", $wrapped),
        );
    }
}
