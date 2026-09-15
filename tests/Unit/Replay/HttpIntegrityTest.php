<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Replay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Exception\ReplayException;
use WorkerSafety\Replay\Http\HttpResponse;
use WorkerSafety\Replay\Http\StreamHttpClient;
use WorkerSafety\Replay\Scenario\ReplayRequest;
use WorkerSafety\Tests\Support\FixtureWorker;

/**
 * Response completeness, against a real socket.
 *
 * The rule these pin down: an expectation can only speak about a response that
 * actually arrived. `body_not_contains: [alice]` is not satisfied by a body
 * that was cut off before alice could appear, so an incomplete response is a
 * transport error (exit 3) rather than a result.
 *
 * The healthy cases are here for the same reason — a completeness check that
 * rejects a 204 or a chunked response would be worse than none.
 */
#[CoversClass(StreamHttpClient::class)]
final class HttpIntegrityTest extends TestCase
{
    private ?FixtureWorker $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function server(): FixtureWorker
    {
        return $this->server ??= FixtureWorker::startHttpIntegrity();
    }

    private function get(string $path, float $timeout = 2.0, string $method = 'GET'): HttpResponse
    {
        return (new StreamHttpClient())->send(
            $this->server()->baseUrl(),
            new ReplayRequest($method, $path),
            $timeout,
        );
    }

    // ---- must be rejected --------------------------------------------------

    /**
     * Headers and half a body, then silence. Before the fix the stream metadata
     * was read before the body, so the timeout was invisible and the partial
     * body was reported as an ordinary 200.
     */
    public function test_a_stalled_body_is_a_transport_error_not_a_short_body(): void
    {
        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/No response from .* within 1s/');

        $this->get('/stall', 1.0);
    }

    /**
     * Content-Length says 100, two bytes arrive, the connection closes.
     */
    public function test_a_truncated_body_is_a_transport_error(): void
    {
        $this->expectException(ReplayException::class);
        $this->expectExceptionMessageMatches('/Incomplete response.*declared 100 byte\(s\) but 2 arrived/');

        $this->get('/truncated');
    }

    // ---- must keep working -------------------------------------------------

    public function test_a_complete_response_is_accepted(): void
    {
        $response = $this->get('/ok');

        self::assertSame(200, $response->status);
        self::assertSame(['user' => null], $response->jsonAsArray());
    }

    public function test_a_legitimately_empty_body_is_accepted(): void
    {
        $response = $this->get('/empty');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
    }

    public function test_204_no_content_is_accepted(): void
    {
        $response = $this->get('/no-content');

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
    }

    /**
     * A 304 may carry the Content-Length of the cached body it is not sending.
     */
    public function test_304_not_modified_is_accepted_despite_a_content_length(): void
    {
        $response = $this->get('/not-modified');

        self::assertSame(304, $response->status);
        self::assertSame('', $response->body);
    }

    /**
     * HEAD returns the Content-Length a GET would produce, with no body.
     */
    public function test_head_is_accepted_despite_a_content_length(): void
    {
        $response = $this->get('/ok', 2.0, 'HEAD');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
    }

    /**
     * The wrapper decodes chunked, so a declared length would not match anyway.
     */
    public function test_a_chunked_response_is_accepted(): void
    {
        $response = $this->get('/chunked');

        self::assertSame(200, $response->status);
        self::assertSame(['user' => null], $response->jsonAsArray());
    }

    /**
     * Connection-close delimited: HTTP gives no way to detect truncation, so it
     * is accepted rather than guessed at.
     */
    public function test_a_response_without_content_length_is_accepted(): void
    {
        $response = $this->get('/no-length');

        self::assertSame(200, $response->status);
        self::assertSame(['user' => null], $response->jsonAsArray());
    }

    public function test_a_404_with_a_complete_body_is_still_a_response(): void
    {
        $response = $this->get('/missing');

        self::assertSame(404, $response->status);
        self::assertSame(['error' => 'not found'], $response->jsonAsArray());
    }
}
