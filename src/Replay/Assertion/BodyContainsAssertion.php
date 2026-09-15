<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Assertion;

use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Result\AssertionResult;

final class BodyContainsAssertion implements ReplayAssertion
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
                ? AssertionResult::pass('body_contains', null, $needle, $needle)
                : AssertionResult::fail(
                    'body_contains',
                    null,
                    $needle,
                    null,
                    sprintf('the response body does not contain %s', json_encode($needle)),
                );
        }

        return $results;
    }
}
