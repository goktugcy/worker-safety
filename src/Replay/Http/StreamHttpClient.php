<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Http;

use WorkerSafety\Exception\ReplayException;
use WorkerSafety\Replay\Scenario\ReplayRequest;

/**
 * HTTP over PHP's native stream wrapper — no transport dependency to install.
 *
 * `ignore_errors` keeps 4xx and 5xx responses readable instead of turning them
 * into warnings with a false body, because a scenario may legitimately expect
 * one. Redirects are not followed: replay is about what this request returned,
 * and a silent hop to another URL would make the result hard to reason about.
 */
final class StreamHttpClient implements HttpClient
{
    public function send(string $baseUrl, ReplayRequest $request, float $timeoutSeconds): HttpResponse
    {
        $url = $this->url($baseUrl, $request->path);
        $body = $request->payload();

        $headers = $request->headers;

        if ($body !== null && $request->isJson()) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($body !== null) {
            $headers['Content-Length'] = (string) strlen($body);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $request->method,
                'header' => $this->headerLines($headers),
                'content' => $body ?? '',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                // Preserve framing so a missing final chunk cannot look complete.
                'auto_decode' => false,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // `error_get_last()` rather than a temporary error handler: installing
        // one would mutate the process-wide handler stack, which is precisely
        // what WS009 reports and what this package tells people not to do from
        // a request path. Comparing against the previous entry avoids picking
        // up an unrelated warning raised earlier in the process.
        $before = error_get_last();
        $handle = @fopen($url, 'rb', false, $context);

        if ($handle === false) {
            $after = error_get_last();
            $message = $after !== null && $after !== $before ? $after['message'] : null;

            throw ReplayException::connectionFailed($url, $this->reason($message));
        }

        try {
            $responseBody = stream_get_contents($handle);

            // Read the metadata *after* the body: `timed_out` reflects the most
            // recent read, so inspecting it before means a stall that happens
            // while the body is arriving is invisible. That is how a half-sent
            // response used to be reported as an ordinary one — and a truncated
            // body can satisfy `body_not_contains` for the simple reason that
            // the rest of it never arrived.
            $meta = stream_get_meta_data($handle);
        } finally {
            fclose($handle);
        }

        if ($meta['timed_out'] === true) {
            throw ReplayException::timedOut($url, $timeoutSeconds);
        }

        // A read that failed outright is not an empty body.
        if ($responseBody === false) {
            throw ReplayException::malformedResponse($url, 'the response body could not be read');
        }

        /** @var list<string> $raw */
        $raw = is_array($meta['wrapper_data'] ?? null) ? array_values(array_filter(
            $meta['wrapper_data'],
            static fn (mixed $line): bool => is_string($line),
        )) : [];

        if ($raw === []) {
            throw ReplayException::malformedResponse($url, 'no status line was returned');
        }

        $status = $this->status($url, $raw);
        $headers = $this->headers($raw);

        if ($this->expectsBody($request, $status)) {
            $encoding = null;
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'transfer-encoding') {
                    $encoding = strtolower(trim($value));
                }
            }
            if ($encoding !== null) {
                if ($encoding !== 'chunked') {
                    throw ReplayException::malformedResponse($url, 'unsupported transfer encoding');
                }
                $responseBody = (new ChunkedBodyDecoder())->decode($url, $responseBody);
            }
        }
        $this->assertComplete($url, $request, $status, $headers, $responseBody);

        return new HttpResponse($status, $headers, $responseBody);
    }

    /**
     * Refuse a response whose body is shorter than it claims to be.
     *
     * An assertion can only speak about a response that actually arrived:
     * "alice is absent from the body" is not established by a body that was cut
     * off before alice could appear. So a short read is a transport error
     * (exit 3), never a result.
     *
     * Chunk framing is checked separately before this length check.
     * The following responses need no Content-Length comparison:
     *
     *  - HEAD, 1xx, 204 and 304 carry no body by definition, and a
     *    `Content-Length` on them describes the body a GET *would* return.
     *  - `Transfer-Encoding: chunked` has already been validated and decoded;
     *    its framing takes precedence over Content-Length.
     *  - A response with neither is delimited by the connection closing, and
     *    HTTP gives no way to tell a complete one from a truncated one.
     *
     * @param array<string, string> $headers
     */
    private function assertComplete(
        string $url,
        ReplayRequest $request,
        int $status,
        array $headers,
        string $body,
    ): void {
        if (!$this->expectsBody($request, $status)) {
            return;
        }

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'transfer-encoding' && trim($value) !== '') {
                return;
            }
        }

        $declared = null;

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-length') {
                $declared = trim($value);
            }
        }

        if ($declared === null || !ctype_digit($declared)) {
            return;
        }

        $expected = (int) $declared;
        $received = strlen($body);

        if ($received === $expected) {
            return;
        }

        throw ReplayException::incompleteBody($url, $expected, $received);
    }

    private function expectsBody(ReplayRequest $request, int $status): bool
    {
        if ($request->method === 'HEAD') {
            return false;
        }

        return $status >= 200 && $status !== 204 && $status !== 304;
    }

    private function url(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * The last status line wins: an intermediate 1xx or a proxy can put more
     * than one in `wrapper_data`.
     *
     * @param list<string> $raw
     */
    private function status(string $url, array $raw): int
    {
        $status = null;

        foreach ($raw as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        if ($status === null) {
            throw ReplayException::malformedResponse($url, 'the status line could not be parsed');
        }

        return $status;
    }

    /**
     * Repeated header names are joined the way HTTP defines it, so a scenario
     * comparing one of them sees every value rather than an arbitrary one.
     *
     * @param list<string> $raw
     *
     * @return array<string, string>
     */
    private function headers(array $raw): array
    {
        $headers = [];

        foreach ($raw as $line) {
            $position = strpos($line, ':');

            if ($position === false) {
                continue;
            }

            $name = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($name === '') {
                continue;
            }

            $existing = $headers[$name] ?? null;
            $headers[$name] = $existing === null ? $value : $existing . ', ' . $value;
        }

        return $headers;
    }

    private function reason(?string $error): string
    {
        if ($error === null) {
            return 'connection failed';
        }

        // PHP prefixes the useful part with the failing function name.
        $cleaned = preg_replace('/^fopen\([^)]*\):\s*/', '', $error) ?? $error;

        return trim($cleaned) === '' ? 'connection failed' : trim($cleaned);
    }
}
