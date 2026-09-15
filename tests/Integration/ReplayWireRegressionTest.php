<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use WorkerSafety\Application\WorkerSafetyApplication;
use WorkerSafety\Replay\Http\StreamHttpClient;
use WorkerSafety\Replay\Scenario\ReplayRequest;
use WorkerSafety\Tests\Support\FixtureWorker;

#[CoversNothing]
final class ReplayWireRegressionTest extends TestCase
{
    public function test_truncated_chunks_are_transport_errors_in_both_formats(): void
    {
        $server = FixtureWorker::startHttpIntegrity();
        $file = tempnam(sys_get_temp_dir(), 'ws-wire-');
        self::assertIsString($file);

        try {
            foreach (['/chunk-no-end', '/chunk-short', '/chunk-trailer-short'] as $path) {
                file_put_contents($file, "version: 1\nname: incomplete\nsteps:\n  - request: {path: $path}\n    expect:\n      status: 200\n      body_not_contains: [alice]\n");
                foreach (['console', 'json'] as $format) {
                    $tester = new CommandTester((new WorkerSafetyApplication())->find('test'));
                    self::assertSame(3, $tester->execute([
                        'scenario' => $file, '--base-url' => $server->baseUrl(), '--format' => $format,
                    ], ['capture_stderr_separately' => true]), $path);
                    self::assertSame('', $tester->getDisplay());
                    self::assertStringContainsString('Malformed HTTP response', $tester->getErrorOutput());
                }
            }
        } finally {
            unlink($file);
            $server->stop();
        }
    }

    public function test_valid_chunk_extensions_trailers_and_empty_body_are_accepted(): void
    {
        $server = FixtureWorker::startHttpIntegrity();

        try {
            foreach (['/chunk-extensions' => 'ok', '/chunk-empty' => ''] as $path => $body) {
                $response = (new StreamHttpClient())->send($server->baseUrl(), new ReplayRequest('GET', $path), 2);
                self::assertSame($body, $response->body);
            }
        } finally {
            $server->stop();
        }
    }

    public function test_request_json_types_survive_yaml_and_real_http_transport(): void
    {
        $server = FixtureWorker::startHttpIntegrity();
        $file = tempnam(sys_get_temp_dir(), 'ws-json-');
        self::assertIsString($file);

        try {
            foreach (['{}', '[]', 'null', 'false', '"hello"', '42', '{config: {}, list: [], nested: [{obj: {}, arr: []}]}'] as $value) {
                file_put_contents($file, "version: 1\nname: echo types\nsteps:\n  - request:\n      method: POST\n      path: /echo\n      json: $value\n    expect: {status: 200}\n");
                $scenario = (new \WorkerSafety\Replay\Scenario\ScenarioLoader())->load($file, $server->baseUrl());
                $response = (new StreamHttpClient())->send($server->baseUrl(), $scenario->steps[0]->request, 2);
                $expected = match ($value) {
                    '{config: {}, list: [], nested: [{obj: {}, arr: []}]}' => '{"config":{},"list":[],"nested":[{"obj":{},"arr":[]}]}',
                    default => $value,
                };
                self::assertSame($expected, $response->body);
            }
        } finally {
            unlink($file);
            $server->stop();
        }
    }
}
