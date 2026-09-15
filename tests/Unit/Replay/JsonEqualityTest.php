<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Replay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Replay\Assertion\JsonEqualsAssertion;
use WorkerSafety\Replay\Assertion\JsonPath;
use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Result\AssertionResult;
use WorkerSafety\Replay\Result\MissingValue;
use WorkerSafety\Replay\Scenario\ScenarioLoader;

/**
 * JSON equality has to use JSON's type model, not PHP's.
 *
 * Decoding to associative arrays merges objects and arrays, so `{}` stops being
 * different from `[]`; comparing with `===` makes key order significant when
 * JSON says it is not. Both sides — the response and the YAML expectation —
 * have to preserve the distinction, or the fix only works in one direction.
 */
#[CoversClass(JsonPath::class)]
#[CoversClass(JsonEqualsAssertion::class)]
#[CoversClass(HttpResponse::class)]
#[CoversClass(MissingValue::class)]
final class JsonEqualityTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    /**
     * Goes through a real scenario file so the YAML side is exercised too.
     *
     * @return array<string, mixed>
     */
    private function expectationsFrom(string $jsonBlock): array
    {
        $path = sys_get_temp_dir() . '/ws-json-' . bin2hex(random_bytes(6)) . '.yaml';
        $this->files[] = $path;

        // Built by concatenation rather than a heredoc: the flexible-heredoc
        // indentation strip would flatten the interpolated line to the same
        // level as `json:`, turning a nested value into a sibling key.
        file_put_contents($path, implode("\n", [
            'version: 1',
            'name: json equality',
            'base_url: http://127.0.0.1:1',
            'steps:',
            '  - request:',
            '      path: /a',
            '    expect:',
            '      json:',
            '        ' . trim($jsonBlock),
            '',
        ]));

        return (new ScenarioLoader())->load($path)->steps[0]->expect->json;
    }

    private function check(string $jsonBlock, string $body): bool
    {
        $results = (new JsonEqualsAssertion($this->expectationsFrom($jsonBlock)))
            ->check(new HttpResponse(200, [], $body));

        foreach ($results as $result) {
            if (!$result->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function comparisons(): iterable
    {
        // Object key order is not part of the document.
        yield 'object key order is irrelevant' => ['d: { a: 1, b: 2 }', '{"d":{"b":2,"a":1}}', true];
        yield 'nested object key order is irrelevant' => [
            '      d: { x: { a: 1, b: 2 } }',
            '{"d":{"x":{"b":2,"a":1}}}',
            true,
        ];

        // Arrays and objects are different types.
        yield 'empty array does not match empty object' => ['d: []', '{"d":{}}', false];
        yield 'empty object does not match empty array' => ['d: {}', '{"d":[]}', false];
        yield 'empty array matches empty array' => ['d: []', '{"d":[]}', true];
        yield 'empty object matches empty object' => ['d: {}', '{"d":{}}', true];
        yield 'populated array does not match object' => ['d: [1]', '{"d":{"0":1}}', false];

        // Array order is part of the document.
        yield 'array order matters' => ['d: [1, 2]', '{"d":[2,1]}', false];
        yield 'array order preserved' => ['d: [1, 2]', '{"d":[1,2]}', true];
        yield 'array length matters' => ['d: [1, 2]', '{"d":[1,2,3]}', false];

        // Scalar types stay distinct.
        yield 'string is not int' => ['d: "1"', '{"d":1}', false];
        yield 'int is not string' => ['d: 1', '{"d":"1"}', false];
        yield 'bool is not int' => ['d: true', '{"d":1}', false];
        yield 'bool is not string' => ['d: true', '{"d":"true"}', false];
        yield 'null is not empty string' => ['d: null', '{"d":""}', false];
        yield 'null matches null' => ['d: null', '{"d":null}', true];

        // The documented numeric loosening, applied at depth.
        yield 'int equals float' => ['d: 1', '{"d":1.0}', true];
        yield 'nested int equals float' => ['d: { n: 1 }', '{"d":{"n":1.0}}', true];
        yield 'int in array equals float' => ['d: [1]', '{"d":[1.0]}', true];

        // Missing is not null, at depth.
        yield 'missing key is not equal to null' => ['d: { a: null }', '{"d":{}}', false];
        yield 'extra key makes objects unequal' => ['d: { a: 1 }', '{"d":{"a":1,"b":2}}', false];
        yield 'present null is equal to null' => ['d: { a: null }', '{"d":{"a":null}}', true];
    }

    #[DataProvider('comparisons')]
    public function test_json_equality(string $jsonBlock, string $body, bool $expected): void
    {
        self::assertSame($expected, $this->check($jsonBlock, $body));
    }

    // ---- the sentinel must not collide with response data -------------------

    /**
     * The old marker was the literal string `__worker_safety_missing__`, so a
     * response containing it was indistinguishable from one missing the field.
     */
    public function test_the_former_marker_string_is_ordinary_data(): void
    {
        $results = (new JsonEqualsAssertion(['u' => '__worker_safety_missing__']))
            ->check(new HttpResponse(200, [], '{"u":"__worker_safety_missing__"}'));

        self::assertTrue($results[0]->passed, 'A literal string must match itself.');
        self::assertFalse($results[0]->actualIsMissing());
        self::assertSame('__worker_safety_missing__', $results[0]->actual);
    }

    public function test_an_absent_field_is_reported_as_missing(): void
    {
        $results = (new JsonEqualsAssertion(['u' => 'anything']))->check(new HttpResponse(200, [], '{}'));

        self::assertFalse($results[0]->passed);
        self::assertTrue($results[0]->actualIsMissing());
    }

    public function test_a_present_null_matches_null_and_is_not_missing(): void
    {
        $results = (new JsonEqualsAssertion(['u' => null]))->check(new HttpResponse(200, [], '{"u":null}'));

        self::assertTrue($results[0]->passed);
        self::assertFalse($results[0]->actualIsMissing());
    }

    public function test_absence_is_not_representable_as_json(): void
    {
        // Whatever a response contains, it can never decode to this value.
        self::assertInstanceOf(MissingValue::class, AssertionResult::MISSING);
        self::assertSame(MissingValue::Instance, JsonPath::get(new \stdClass(), 'nope'));
        self::assertNotSame(MissingValue::Instance, json_decode('"__worker_safety_missing__"'));
    }

    public function test_dot_paths_traverse_objects_and_lists(): void
    {
        $document = json_decode('{"tenant":{"id":42},"items":[{"id":100},{"id":101}]}');

        self::assertSame(42, JsonPath::get($document, 'tenant.id'));
        self::assertSame(101, JsonPath::get($document, 'items.1.id'));
        self::assertSame(MissingValue::Instance, JsonPath::get($document, 'tenant.name'));
        self::assertSame(MissingValue::Instance, JsonPath::get($document, 'items.9.id'));
    }
}
