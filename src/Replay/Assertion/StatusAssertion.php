<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Assertion;

use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Result\AssertionResult;

final class StatusAssertion implements ReplayAssertion
{
    public function __construct(private readonly int $expected)
    {
    }

    public function check(HttpResponse $response): array
    {
        return [
            $response->status === $this->expected
                ? AssertionResult::pass('status', null, $this->expected, $response->status)
                : AssertionResult::fail('status', null, $this->expected, $response->status),
        ];
    }
}
