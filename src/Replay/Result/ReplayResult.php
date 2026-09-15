<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Result;

use WorkerSafety\Application\ExitCode;

/**
 * The outcome of a whole replay run.
 */
final class ReplayResult
{
    /**
     * @param list<StepResult> $steps
     */
    public function __construct(
        public readonly string $scenarioName,
        public readonly string $target,
        public readonly array $steps,
        public readonly float $durationSeconds = 0.0,
    ) {
    }

    public function passed(): bool
    {
        foreach ($this->steps as $step) {
            if (!$step->passed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every failed assertion across every step, in execution order.
     *
     * @return list<AssertionResult>
     */
    public function failures(): array
    {
        $failures = [];

        foreach ($this->steps as $step) {
            foreach ($step->failures() as $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    public function assertionCount(): int
    {
        $count = 0;

        foreach ($this->steps as $step) {
            $count += count($step->assertions);
        }

        return $count;
    }

    /**
     * A failed expectation is exit 1, mirroring a scan that reached its
     * severity threshold. Transport failures never reach this point: they are
     * thrown as ReplayException and become exit 3.
     */
    public function exitCode(): ExitCode
    {
        return $this->passed() ? ExitCode::Success : ExitCode::FindingsAboveThreshold;
    }
}
