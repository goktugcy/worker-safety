<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Replay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Replay\Assertion\BodyContainsAssertion;
use WorkerSafety\Replay\Assertion\BodyNotContainsAssertion;
use WorkerSafety\Replay\Assertion\HeaderEqualsAssertion;
use WorkerSafety\Replay\Assertion\JsonEqualsAssertion;
use WorkerSafety\Replay\Assertion\JsonPath;
use WorkerSafety\Replay\Assertion\StatusAssertion;
use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Result\AssertionResult;

#[CoversClass(StatusAssertion::class)]
#[CoversClass(JsonEqualsAssertion::class)]
#[CoversClass(HeaderEqualsAssertion::class)]
#[CoversClass(BodyContainsAssertion::class)]
#[CoversClass(BodyNotContainsAssertion::class)]
#[CoversClass(JsonPath::class)]
#[CoversClass(AssertionResult::class)]
#[CoversClass(HttpResponse::class)]
final class AssertionTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function response(string $body, int $status = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, $headers, $body);
    }

    public function test_status_assertion(): void
    {
        $pass = (new StatusAssertion(200))->check($this->response('{}'))[0];
        self::assertTrue($pass->passed);
        self::assertSame(200, $pass->actual);

        $fail = (new StatusAssertion(200))->check($this->response('{}', 500))[0];
        self::assertFalse($fail->passed);
        self::assertSame(200, $fail->expected);
        self::assertSame(500, $fail->actual);
        self::assertSame('status', $fail->label());
    }

    public function test_json_equality_on_a_top_level_key(): void
    {
        $results = (new JsonEqualsAssertion(['user' => null]))->check($this->response('{"user":null}'));

        self::assertTrue($results[0]->passed);
    }

    /**
     * The shape the leak case produces.
     */
    public function test_json_equality_reports_the_observed_value(): void
    {
        $results = (new JsonEqualsAssertion(['user' => null]))->check($this->response('{"user":"alice"}'));

        self::assertFalse($results[0]->passed);
        self::assertSame('user', $results[0]->path);
        self::assertNull($results[0]->expected);
        self::assertSame('alice', $results[0]->actual);
        self::assertSame('json.user', $results[0]->label());
    }

    public function test_nested_dot_paths_and_array_indexes(): void
    {
        $body = '{"tenant":{"id":42},"items":[{"id":100},{"id":101}]}';

        $results = (new JsonEqualsAssertion([
            'tenant.id' => 42,
            'items.0.id' => 100,
            'items.1.id' => 101,
        ]))->check($this->response($body));

        foreach ($results as $result) {
            self::assertTrue($result->passed, $result->label());
        }
    }

    public function test_a_missing_path_is_distinct_from_null(): void
    {
        $results = (new JsonEqualsAssertion(['tenant.id' => 42]))->check($this->response('{"user":null}'));

        self::assertFalse($results[0]->passed);
        self::assertTrue($results[0]->actualIsMissing());
        self::assertSame('the response has no value at this path', $results[0]->detail);
    }

    public function test_an_explicit_null_is_not_treated_as_missing(): void
    {
        $results = (new JsonEqualsAssertion(['user' => 'bob']))->check($this->response('{"user":null}'));

        self::assertFalse($results[0]->passed);
        self::assertFalse($results[0]->actualIsMissing(), 'null was present, so it is not missing.');
        self::assertNull($results[0]->actual);
    }

    public function test_descending_through_a_scalar_is_missing_not_a_crash(): void
    {
        self::assertSame(AssertionResult::MISSING, JsonPath::get(['user' => 'alice'], 'user.name'));
        self::assertSame(AssertionResult::MISSING, JsonPath::get(null, 'user'));
        self::assertSame(AssertionResult::MISSING, JsonPath::get(['a' => 1], ''));
    }

    public function test_an_invalid_json_body_fails_every_json_expectation(): void
    {
        $results = (new JsonEqualsAssertion(['user' => null]))->check($this->response('<html>not json</html>'));

        self::assertCount(1, $results);
        self::assertFalse($results[0]->passed);
        self::assertSame('the response body is not valid JSON', $results[0]->detail);
        self::assertTrue($results[0]->actualIsMissing());
    }

    public function test_integers_and_floats_compare_equal(): void
    {
        $results = (new JsonEqualsAssertion(['n' => 1]))->check($this->response('{"n":1.0}'));

        self::assertTrue($results[0]->passed);
    }

    public function test_header_matching_is_case_insensitive(): void
    {
        $response = $this->response('{}', 200, ['X-Tenant' => 'foo']);

        self::assertTrue((new HeaderEqualsAssertion(['x-tenant' => 'foo']))->check($response)[0]->passed);
        self::assertTrue((new HeaderEqualsAssertion(['X-TENANT' => 'foo']))->check($response)[0]->passed);
    }

    public function test_a_missing_header_is_reported_as_missing(): void
    {
        $result = (new HeaderEqualsAssertion(['X-Tenant' => 'foo']))->check($this->response('{}'))[0];

        self::assertFalse($result->passed);
        self::assertTrue($result->actualIsMissing());
        self::assertSame('header.X-Tenant', $result->label());
    }

    public function test_body_contains(): void
    {
        self::assertTrue((new BodyContainsAssertion(['success']))->check($this->response('{"r":"success"}'))[0]->passed);
        self::assertFalse((new BodyContainsAssertion(['success']))->check($this->response('{}'))[0]->passed);
    }

    public function test_body_not_contains(): void
    {
        self::assertTrue((new BodyNotContainsAssertion(['alice']))->check($this->response('{"user":null}'))[0]->passed);

        $fail = (new BodyNotContainsAssertion(['alice']))->check($this->response('{"user":"alice"}'))[0];
        self::assertFalse($fail->passed);
        self::assertStringContainsString('contains', (string) $fail->detail);
    }

    public function test_an_empty_body_is_not_json(): void
    {
        self::assertFalse($this->response('')->bodyIsJson());
        self::assertNull($this->response('')->json());
    }
}
