<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Replay\Result\AssertionResult;
use WorkerSafety\Replay\Result\ReplayResult;
use WorkerSafety\Replay\Result\StepResult;

/**
 * Stable machine-readable replay report.
 *
 * Versioned independently of the scan report so the two schemas can move apart
 * without either breaking. Request headers are never echoed: a scenario may
 * carry tokens or cookies, and a CI log is not the place for them.
 */
final class ReplayJsonReporter
{
    public const SCHEMA_VERSION = 1;

    public function report(ReplayResult $result, OutputInterface $output): void
    {
        $output->writeln($this->encode($result));
    }

    public function encode(ReplayResult $result): string
    {
        return (string) json_encode(
            $this->toArray($result),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(ReplayResult $result): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'scenario' => [
                'name' => $result->scenarioName,
                'target' => $result->target,
            ],
            'passed' => $result->passed(),
            'steps' => array_map(
                fn (StepResult $step): array => $this->step($step),
                $result->steps,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function step(StepResult $step): array
    {
        return [
            'id' => $step->id,
            'request' => $step->request,
            'status' => $step->status,
            'passed' => $step->passed(),
            'duration_seconds' => round($step->durationSeconds, 4),
            'assertions' => array_map(
                fn (AssertionResult $assertion): array => $this->assertion($assertion),
                $step->assertions,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assertion(AssertionResult $assertion): array
    {
        return [
            'type' => $assertion->type,
            'path' => $assertion->path,
            'expected' => $assertion->expected,
            // A path that was absent is reported as null with `actual_missing`
            // set, so consumers can tell it apart from an observed null.
            'actual' => $assertion->actualIsMissing() ? null : $assertion->actual,
            'actual_missing' => $assertion->actualIsMissing(),
            'passed' => $assertion->passed,
            'detail' => $assertion->detail,
        ];
    }
}
