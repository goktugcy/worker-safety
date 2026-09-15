<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Replay\Result\AssertionResult;
use WorkerSafety\Replay\Result\ReplayResult;
use WorkerSafety\Replay\Result\StepResult;

/**
 * Human readable replay report.
 *
 * The language stays neutral on purpose. Replay observes that a later request
 * could read what an earlier one wrote; whether that is a security problem
 * depends on the data and on who can reach the endpoint, which the tool cannot
 * know. So it reports "cross-request state remained observable" and leaves the
 * word vulnerability to the reader.
 *
 * Request headers are never printed — scenarios carry tokens.
 */
final class ReplayConsoleReporter
{
    private const WIDTH = 76;

    public function report(ReplayResult $result, OutputInterface $output): void
    {
        ConsoleStyles::register($output);

        $output->writeln('');
        $output->writeln(sprintf('<ws-heading>%s Replay</ws-heading>', ApplicationInfo::NAME));
        $output->writeln('');

        $output->writeln('<ws-heading>Scenario</ws-heading>');
        $output->writeln('  ' . self::escape($result->scenarioName));
        $output->writeln('');

        $output->writeln('<ws-heading>Target</ws-heading>');
        $output->writeln('  ' . self::escape($result->target));
        $output->writeln('');

        foreach ($result->steps as $index => $step) {
            $this->writeStep($index + 1, $step, $output);
        }

        $this->writeSummary($result, $output);
    }

    private function writeStep(int $number, StepResult $step, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            '<ws-heading>Step %d</ws-heading>  %s',
            $number,
            self::escape($step->id),
        ));

        $output->writeln(sprintf('  <ws-pass>%s</ws-pass> %s', '✓', self::escape($step->request)));

        foreach ($step->assertions as $assertion) {
            $this->writeAssertion($assertion, $output);
        }

        $output->writeln('');
    }

    private function writeAssertion(AssertionResult $assertion, OutputInterface $output): void
    {
        if ($assertion->passed) {
            $output->writeln(sprintf(
                '  <ws-pass>✓</ws-pass> %s',
                self::escape($this->passLabel($assertion)),
            ));

            return;
        }

        $output->writeln(sprintf('  <ws-fail>✗</ws-fail> %s', self::escape($assertion->label())));
        $output->writeln('');
        $output->writeln('    Expected:');
        $output->writeln('      ' . self::escape($this->render($assertion->expected)));
        $output->writeln('    Observed:');
        $output->writeln('      ' . self::escape(
            $assertion->actualIsMissing() ? '(no value at this path)' : $this->render($assertion->actual),
        ));

        if ($assertion->detail !== null) {
            $output->writeln('');
            $output->writeln('    <ws-muted>' . self::escape($assertion->detail) . '</ws-muted>');
        }

        $output->writeln('');
    }

    private function passLabel(AssertionResult $assertion): string
    {
        if ($assertion->type === 'status') {
            return 'status = ' . $this->render($assertion->actual);
        }

        if ($assertion->path !== null) {
            return $assertion->label() . ' = ' . $this->render($assertion->actual);
        }

        return $assertion->label();
    }

    private function writeSummary(ReplayResult $result, OutputInterface $output): void
    {
        if ($result->passed()) {
            $output->writeln('<ws-pass>Result: PASSED</ws-pass>');
            $output->writeln('');

            foreach ($this->wrap(
                sprintf(
                    '%d assertion(s) across %d step(s) held. This is evidence about the requests that were '
                    . 'replayed against this instance, not a proof that no state is retained anywhere.',
                    $result->assertionCount(),
                    count($result->steps),
                ),
            ) as $line) {
                $output->writeln('<ws-muted>' . self::escape($line) . '</ws-muted>');
            }

            $output->writeln('');

            return;
        }

        $output->writeln('<ws-fail>Result: FAILED</ws-fail>');
        $output->writeln('');

        foreach ($this->wrap(
            sprintf(
                '%d of %d assertion(s) did not hold. Where a later step observed a value written by an earlier '
                . 'one, cross-request state remained observable: the process kept it between requests instead of '
                . 'discarding it with the request that created it.',
                count($result->failures()),
                $result->assertionCount(),
            ),
        ) as $line) {
            $output->writeln(self::escape($line));
        }

        $output->writeln('');
    }

    private function render(mixed $value): string
    {
        if (is_string($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? gettype($value) : $encoded;
    }

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
