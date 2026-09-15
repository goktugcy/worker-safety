<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Replay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Replay\Scenario\ReplayScenario;
use WorkerSafety\Replay\Scenario\ScenarioLoader;

#[CoversClass(ScenarioLoader::class)]
#[CoversClass(ReplayScenario::class)]
final class ScenarioLoaderTest extends TestCase
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

    private function write(string $yaml): string
    {
        $path = sys_get_temp_dir() . '/ws-scenario-' . bin2hex(random_bytes(6)) . '.yaml';
        file_put_contents($path, $yaml);
        $this->files[] = $path;

        return $path;
    }

    private function load(string $yaml, ?string $baseUrl = null): ReplayScenario
    {
        return (new ScenarioLoader())->load($this->write($yaml), $baseUrl);
    }

    public function test_it_loads_a_valid_scenario(): void
    {
        $scenario = $this->load(<<<'YAML'
            version: 1
            name: shared request context leak
            base_url: http://127.0.0.1:8080/
            steps:
              - id: seed
                request:
                  method: POST
                  path: /context
                  headers:
                    Authorization: Bearer token
                  json:
                    user: alice
                expect:
                  status: 200
              - id: observe
                request:
                  path: /context
                expect:
                  json:
                    user: null
                  body_not_contains:
                    - alice
            YAML);

        self::assertSame('shared request context leak', $scenario->name);
        // The trailing slash is normalised away so paths concatenate cleanly.
        self::assertSame('http://127.0.0.1:8080', $scenario->baseUrl);
        self::assertSame(2, $scenario->stepCount());

        $seed = $scenario->steps[0];
        self::assertSame('seed', $seed->id);
        self::assertSame('POST', $seed->request->method);
        self::assertSame(['Authorization' => 'Bearer token'], $seed->request->headers);
        self::assertTrue($seed->request->isJson());
        self::assertSame('{"user":"alice"}', $seed->request->payload());
        self::assertSame(200, $seed->expect->status);

        $observe = $scenario->steps[1];
        // Method defaults to GET.
        self::assertSame('GET', $observe->request->method);
        self::assertNull($observe->request->payload());
        self::assertSame(['user' => null], $observe->expect->json);
        self::assertSame(['alice'], $observe->expect->bodyNotContains);
    }

    public function test_a_step_without_an_id_gets_a_positional_one(): void
    {
        $scenario = $this->load(<<<'YAML'
            version: 1
            name: unnamed steps
            base_url: http://localhost
            steps:
              - request:
                  path: /a
              - request:
                  path: /b
            YAML);

        self::assertSame(['step-1', 'step-2'], array_map(
            static fn (object $step): string => $step->id,
            $scenario->steps,
        ));
    }

    public function test_the_base_url_option_overrides_the_file(): void
    {
        $scenario = $this->load(<<<'YAML'
            version: 1
            name: overridden
            base_url: http://127.0.0.1:8080
            steps:
              - request:
                  path: /a
            YAML, 'http://127.0.0.1:9999');

        self::assertSame('http://127.0.0.1:9999', $scenario->baseUrl);
    }

    public function test_the_base_url_may_come_only_from_the_option(): void
    {
        $scenario = $this->load(<<<'YAML'
            version: 1
            name: no base url in file
            steps:
              - request:
                  path: /a
            YAML, 'http://127.0.0.1:9999');

        self::assertSame('http://127.0.0.1:9999', $scenario->baseUrl);
    }

    public function test_a_missing_file_is_a_configuration_error(): void
    {
        $this->expectException(ConfigurationException::class);

        (new ScenarioLoader())->load('/definitely/not/here.yaml');
    }

    public function test_malformed_yaml_is_reported_with_the_file_name(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Could not parse scenario file/');

        $this->load("version: 1\nname: broken\nsteps:\n  - [unclosed\n");
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rejections(): iterable
    {
        yield 'unsupported version' => [
            "version: 2\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n",
            'Unsupported scenario version',
        ];

        yield 'missing version' => [
            "name: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n",
            'must declare',
        ];

        yield 'unknown top-level key' => [
            "version: 1\nname: n\nbase_url: http://x\nretries: 3\nsteps:\n  - request:\n      path: /a\n",
            'retries',
        ];

        yield 'unknown step key' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n    repeat: 2\n",
            'repeat',
        ];

        yield 'unknown request key' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n      query: x\n",
            'query',
        ];

        yield 'unknown expect key' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n    expect:\n      cookies: x\n",
            'cookies',
        ];

        yield 'json and body together' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n      json: {a: 1}\n      body: raw\n",
            'either json or body',
        ];

        yield 'unsupported method' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      method: TRACE\n      path: /a\n",
            'Method must be one of',
        ];

        yield 'missing path' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      method: GET\n",
            'needs a path',
        ];

        yield 'no steps' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps: []\n",
            'non-empty list of steps',
        ];

        yield 'missing base url' => [
            "version: 1\nname: n\nsteps:\n  - request:\n      path: /a\n",
            'needs a base_url',
        ];

        yield 'relative base url' => [
            "version: 1\nname: n\nbase_url: 127.0.0.1:8080\nsteps:\n  - request:\n      path: /a\n",
            'absolute http:// or https:// URL',
        ];

        yield 'duplicate step id' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - id: a\n    request:\n      path: /a\n  - id: a\n    request:\n      path: /b\n",
            'used more than once',
        ];

        yield 'status out of range' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n    expect:\n      status: 99\n",
            'between 100 and 599',
        ];

        yield 'expect json as a list' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n    expect:\n      json:\n        - user\n",
            'mapping of dot-paths',
        ];

        yield 'body_contains not a list' => [
            "version: 1\nname: n\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n    expect:\n      body_contains: ok\n",
            'must be a list of strings',
        ];

        yield 'root is a list' => ["- version: 1\n", 'YAML mapping at the root'];

        yield 'missing name' => [
            "version: 1\nbase_url: http://x\nsteps:\n  - request:\n      path: /a\n",
            'non-empty name',
        ];
    }

    #[DataProvider('rejections')]
    public function test_it_rejects_invalid_scenarios(string $yaml, string $expectedMessage): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedMessage, '/') . '/');

        $this->load($yaml);
    }
}
