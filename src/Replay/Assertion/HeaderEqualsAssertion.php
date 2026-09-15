<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Assertion;

use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Result\AssertionResult;
use WorkerSafety\Replay\Result\MissingValue;

/**
 * Header names are case-insensitive per RFC 9110, and HttpResponse::header()
 * already folds them, so a scenario may write `X-Tenant` or `x-tenant`.
 */
final class HeaderEqualsAssertion implements ReplayAssertion
{
    /**
     * @param array<string, string> $expectations
     */
    public function __construct(private readonly array $expectations)
    {
    }

    public function check(HttpResponse $response): array
    {
        $results = [];

        foreach ($this->expectations as $name => $expected) {
            $actual = $response->header($name);

            if ($actual === null) {
                $results[] = AssertionResult::fail(
                    'header_equals',
                    $name,
                    $expected,
                    MissingValue::Instance,
                    'the response did not send this header',
                );

                continue;
            }

            $results[] = $actual === $expected
                ? AssertionResult::pass('header_equals', $name, $expected, $actual)
                : AssertionResult::fail('header_equals', $name, $expected, $actual);
        }

        return $results;
    }
}
