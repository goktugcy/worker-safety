<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Result;

/**
 * Everything one step produced.
 */
final class StepResult
{
    /**
     * @param list<AssertionResult> $assertions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $request,
        public readonly int $status,
        public readonly array $assertions,
        public readonly float $durationSeconds,
    ) {
    }

    public function passed(): bool
    {
        foreach ($this->assertions as $assertion) {
            if (!$assertion->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<AssertionResult>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->assertions,
            static fn (AssertionResult $assertion): bool => !$assertion->passed,
        ));
    }
}
