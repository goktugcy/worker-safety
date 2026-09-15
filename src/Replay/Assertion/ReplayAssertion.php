<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Assertion;

use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Result\AssertionResult;

/**
 * One check against one response.
 *
 * An assertion never throws for a failed expectation — it returns a result, so
 * a run reports every failure instead of stopping at the first.
 */
interface ReplayAssertion
{
    /**
     * @return list<AssertionResult>
     */
    public function check(HttpResponse $response): array;
}
