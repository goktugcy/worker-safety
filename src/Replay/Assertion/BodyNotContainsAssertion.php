<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Assertion;

use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Result\AssertionResult;

/**
 * The other way to write a leak expectation: rather than pinning a value to
 * null, state that an earlier request's data must not appear at all.
 */
final class BodyNotContainsAssertion implements ReplayAssertion
{
    /**
     * @param list<string> $needles
     */
    public function __construct(private readonly array $needles)
    {
    }

    public function check(HttpResponse $response): array
    {
        $results = [];

        foreach ($this->needles as $needle) {
            $results[] = str_contains($response->body, $needle)
                ? AssertionResult::fail(
                    'body_not_contains',
                    null,
                    $needle,
                    $needle,
                    sprintf('the response body contains %s', json_encode($needle)),
                )
                : AssertionResult::pass('body_not_contains', null, $needle, null);
        }

        return $results;
    }
}
