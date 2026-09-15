<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Assertion;

use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Result\AssertionResult;
use WorkerSafety\Replay\Result\MissingValue;

/**
 * Compares values at dot-paths in the JSON body.
 *
 * This is the assertion the cross-request case rests on: `user: null` says the
 * response must not carry a user, and observing `"alice"` there is the proof
 * that a later request saw what an earlier one wrote.
 */
final class JsonEqualsAssertion implements ReplayAssertion
{
    /**
     * @param array<string, mixed> $expectations dot-path => expected value
     */
    public function __construct(private readonly array $expectations)
    {
    }

    public function check(HttpResponse $response): array
    {
        if (!$response->bodyIsJson()) {
            $results = [];

            foreach ($this->expectations as $path => $expected) {
                $results[] = AssertionResult::fail(
                    'json_equals',
                    $path,
                    $expected,
                    MissingValue::Instance,
                    'the response body is not valid JSON',
                );
            }

            return $results;
        }

        $document = $response->json();
        $results = [];

        foreach ($this->expectations as $path => $expected) {
            $actual = JsonPath::get($document, $path);

            if ($actual instanceof MissingValue) {
                $results[] = AssertionResult::fail(
                    'json_equals',
                    $path,
                    $expected,
                    MissingValue::Instance,
                    'the response has no value at this path',
                );

                continue;
            }

            $results[] = JsonPath::equals($expected, $actual)
                ? AssertionResult::pass('json_equals', $path, $expected, $actual)
                : AssertionResult::fail('json_equals', $path, $expected, $actual);
        }

        return $results;
    }
}
