<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Scenario;

/**
 * A validated replay scenario.
 */
final class ReplayScenario
{
    /**
     * The only schema version this release accepts.
     */
    public const VERSION = 1;

    /**
     * @param list<ReplayStep> $steps
     */
    public function __construct(
        public readonly string $name,
        public readonly string $baseUrl,
        public readonly array $steps,
        public readonly ?string $sourcePath = null,
    ) {
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }
}
