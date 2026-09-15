<?php

declare(strict_types=1);

/**
 * A rude HTTP server, for transport tests only.
 *
 * Serves one deliberately malformed or edge-case response per path so that the
 * client's completeness handling can be exercised against a real socket rather
 * than a mock. The healthy paths matter as much as the broken ones: a rule that
 * rejects truncated bodies is only useful if it still accepts a 204, a HEAD or
 * a chunked response.
 *
 * Usage: php server.php [port] [idleTimeout]  — prints "PORT=<n>" once listening.
 * Same argument shape as the other replay fixtures so one harness starts them all.
 */

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$idleTimeout = isset($argv[2]) ? (float) $argv[2] : 30.0;

$server = @stream_socket_server(sprintf('tcp://127.0.0.1:%d', $port), $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, sprintf("could not listen: %s (%d)\n", $errstr, $errno));

    exit(1);
}

$name = stream_socket_get_name($server, false);
fwrite(STDOUT, sprintf("PORT=%d\n", (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1)));

while (true) {
    $client = @stream_socket_accept($server, $idleTimeout);

    if ($client === false) {
        break;
    }

    $head = '';

    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($client, 4096);

        if ($chunk === false || $chunk === '') {
            break;
        }

        $head .= $chunk;
    }

    if (preg_match('#^(\S+)\s+(\S+)\s#', $head, $m) !== 1) {
        fclose($client);

        continue;
    }

    $method = strtoupper($m[1]);
    $path = (string) parse_url($m[2], PHP_URL_PATH);

    if ($path === '/__shutdown') {
        fclose($client);

        break;
    }

    if ($path === '/echo') {
        [$headers, $body] = explode("\r\n\r\n", $head, 2);
        preg_match('/Content-Length:\s*(\d+)/i', $headers, $size);
        $length = (int) ($size[1] ?? 0);
        while (strlen($body) < $length) {
            $part = fread($client, $length - strlen($body));
            if ($part === false || $part === '') {
                break;
            }
            $body .= $part;
        }
        send($client, 200, 'OK', $body);
    } else {
        serve($client, $method, $path);
    }
    fclose($client);
}

fclose($server);

function serve(mixed $client, string $method, string $path): void
{
    // ---- responses that must be rejected as incomplete ----

    if ($path === '/stall') {
        // Headers and part of the body, then silence. The body never completes,
        // so nothing can be asserted about what it does or does not contain.
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 40\r\n\r\n");
        fwrite($client, '{"user":"ali');
        sleep(5);

        return;
    }

    if ($path === '/truncated') {
        // Declares 100 bytes, sends 2, hangs up.
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 100\r\n\r\n");
        fwrite($client, 'ok');

        return;
    }

    $chunked = [
        '/chunk-no-end' => "2\r\nok\r\n",
        '/chunk-short' => "A\r\nok",
        '/chunk-trailer-short' => "2\r\nok\r\n0\r\nX-Test: yes\r\n",
        '/chunk-extensions' => "2;foo=bar\r\nok\r\n0\r\nX-Test: yes\r\n\r\n",
        '/chunk-empty' => "0\r\n\r\n",
    ];
    if (isset($chunked[$path])) {
        fwrite($client, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n" . $chunked[$path]);
        return;
    }

    // ---- healthy shapes that must keep working ----

    if ($path === '/ok') {
        $body = '{"user":null}';

        // A HEAD response carries the Content-Length a GET would return, with
        // no body. Treating that as truncated would break every HEAD request.
        if ($method === 'HEAD') {
            fwrite($client, sprintf(
                "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n",
                strlen($body),
            ));

            return;
        }

        send($client, 200, 'OK', $body);

        return;
    }

    if ($path === '/empty') {
        send($client, 200, 'OK', '');

        return;
    }

    if ($path === '/no-content') {
        fwrite($client, "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n");

        return;
    }

    if ($path === '/not-modified') {
        // 304 may legally carry a Content-Length describing the cached body.
        fwrite($client, "HTTP/1.1 304 Not Modified\r\nContent-Length: 13\r\nConnection: close\r\n\r\n");

        return;
    }

    if ($path === '/chunked') {
        // Valid multi-chunk framing, including the terminating empty chunk.
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\n\r\n");
        fwrite($client, "7\r\n{\"user\"\r\n");
        fwrite($client, "6\r\n:null}\r\n");
        fwrite($client, "0\r\n\r\n");

        return;
    }

    if ($path === '/shapes') {
        // One response carrying every distinction JSON equality has to keep:
        // object key order, {} versus [], the former missing-marker string as
        // ordinary data, and a float that must still equal an integer.
        send($client, 200, 'OK', '{"obj":{"b":2,"a":1},"empty_obj":{},"empty_arr":[],"lit":"__worker_safety_missing__","n":1.0,"present_null":null}');

        return;
    }

    if ($path === '/no-length') {
        // Delimited by the connection closing: HTTP offers no way to tell a
        // complete body from a truncated one here, so it is accepted.
        fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n");
        fwrite($client, '{"user":null}');

        return;
    }

    send($client, 404, 'Not Found', '{"error":"not found"}');
}

function send(mixed $client, int $status, string $reason, string $body): void
{
    fwrite($client, sprintf(
        "HTTP/1.1 %d %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
        $status,
        $reason,
        strlen($body),
        $body,
    ));
}
