<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Scenario;

/**
 * One request/expectation pair, in the order the scenario declares it.
 *
 * Order is the whole point: replay only demonstrates cross-request behaviour
 * because step N runs after step N-1 against the same process.
 */
final class ReplayStep
{
    public function __construct(
        public readonly string $id,
        public readonly ReplayRequest $request,
        public readonly ReplayExpectation $expect,
    ) {
    }
}
