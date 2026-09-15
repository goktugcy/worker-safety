<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Replay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Exception\ReplayException;
use WorkerSafety\Replay\Http\StreamHttpClient;
use WorkerSafety\Replay\Scenario\ReplayRequest;
use WorkerSafety\Tests\Support\FixtureWorker;

/**
 * Transport behaviour against a real socket.
 *
 * The failure paths matter as much as the happy one: a connection that was
 * refused says nothing about cross-request state, so it must surface as a
 * transport error rather than as a failed expectation.
 */
#[CoversClass(StreamHttpClient::class)]
final class StreamHttpClientTest extends TestCase
{
    private ?FixtureWorker $worker = null;

    protected function tearDown(): void
    {
        $this->worker?->stop();
        $this->worker = null;
    }

    private function worker(): FixtureWorker
    {
        return $this->worker ??= FixtureWorker::start();
    }

    public function test_a_get_request(): void
    {
        $response = (new StreamHttpClient())->send(
            $this->worker()->baseUrl(),
            new ReplayRequest('GET', '/context'),
            5.0,
        );

        self::assertSame(200, $response->status);
        self::assertTrue($response->bodyIsJson());
        self::assertSame(['user' => null], $response->jsonAsArray());
    }

    public function test_a_post_request_with_a_json_body(): void
    {
        $client = new StreamHttpClient();
        $base = $this->worker()->baseUrl();

        $post = $client->send(
            $base,
            new ReplayRequest('POST', '/context', [], ['user' => 'alice'], null, true),
            5.0,
        );

        self::assertSame(200, $post->status);
        self::assertSame(['ok' => true, 'user' => 'alice'], $post->jsonAsArray());
    }

    public function test_custom_headers_are_sent(): void
    {
        // The fixture echoes nothing back, so this asserts the request is
        // accepted and answered rather than rejected by the header handling.
        $response = (new StreamHttpClient())->send(
            $this->worker()->baseUrl(),
            new ReplayRequest('GET', '/context', ['Authorization' => 'Bearer t0ken', 'X-Trace' => 'abc']),
            5.0,
        );

        self::assertSame(200, $response->status);
    }

    public function test_a_non_2xx_response_is_returned_rather_than_thrown(): void
    {
        $response = (new StreamHttpClient())->send(
            $this->worker()->baseUrl(),
            new ReplayRequest('GET', '/nope'),
            5.0,
        );

        self::assertSame(404, $response->status);
        self::assertSame(['error' => 'not found'], $response->jsonAsArray());
    }

    public function test_response_headers_are_exposed_case_insensitively(): void
    {
        $response = (new StreamHttpClient())->send(
            $this->worker()->baseUrl(),
            new ReplayRequest('GET', '/context'),
            5.0,
        );

        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('application/json', $response->header('content-type'));
        self::assertNull($response->header('X-Absent'));
    }

    public function test_a_refused_connection_is_a_replay_exception(): void
    {
        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/Could not reach/');

        // Port 9 is the discard port and is not listening in CI.
        (new StreamHttpClient())->send('http://127.0.0.1:9', new ReplayRequest('GET', '/context'), 1.0);
    }

    public function test_an_unresolvable_host_is_a_replay_exception(): void
    {
        $this->expectException(ReplayException::class);

        (new StreamHttpClient())->send(
            'http://worker-safety.invalid',
            new ReplayRequest('GET', '/context'),
            2.0,
        );
    }

    /**
     * A socket that accepts and then says nothing must time out, not hang the
     * suite.
     */
    public function test_a_silent_server_times_out(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server);

        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        try {
            $this->expectException(ReplayException::class);

            (new StreamHttpClient())->send(
                'http://127.0.0.1:' . $port,
                new ReplayRequest('GET', '/context'),
                0.5,
            );
        } finally {
            fclose($server);
        }
    }
}
