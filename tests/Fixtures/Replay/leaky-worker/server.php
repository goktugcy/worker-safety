<?php

declare(strict_types=1);

/**
 * A deliberately leaky persistent worker, for tests only.
 *
 * This exists so the replay acceptance test needs no FrankenPHP, no Octane and
 * no network: it is a single PHP process that accepts HTTP on a loopback socket
 * and serves request after request, which is the one property that makes
 * cross-request state observable.
 *
 * The leak is one line — `new RequestContext()` sits *outside* the accept loop,
 * so every request shares the object. Move it inside and the replay passes.
 *
 * Usage: php server.php [port]   (port 0, the default, picks a free one)
 * Prints "PORT=<n>" on stdout once listening, so a test can discover the port
 * without guessing or racing.
 */

require __DIR__ . '/../static-safe/RequestContext.php';

use WorkerSafety\Tests\Fixtures\Replay\StaticSafe\RequestContext;

$port = isset($argv[1]) ? (int) $argv[1] : 0;
$idleTimeout = isset($argv[2]) ? (float) $argv[2] : 30.0;

$server = @stream_socket_server(sprintf('tcp://127.0.0.1:%d', $port), $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, sprintf("could not listen: %s (%d)\n", $errstr, $errno));

    exit(1);
}

$name = stream_socket_get_name($server, false);
$boundPort = $name === false ? 0 : (int) substr($name, (int) strrpos($name, ':') + 1);

fwrite(STDOUT, sprintf("PORT=%d\n", $boundPort));

// THE LEAK: created once, before the loop, and reused by every request.
$context = new RequestContext();

$running = true;

while ($running) {
    $client = @stream_socket_accept($server, $idleTimeout);

    if ($client === false) {
        // Nothing connected within the idle window; stop rather than linger as
        // an orphan process if the test harness went away.
        break;
    }

    stream_set_timeout($client, 5);

    $request = read_request($client);

    if ($request === null) {
        fclose($client);

        continue;
    }

    [$method, $path, $body] = $request;

    if ($path === '/__shutdown') {
        $running = false;
        respond($client, 200, ['ok' => true]);
        fclose($client);

        break;
    }

    respond($client, ...handle($method, $path, $body, $context));
    fclose($client);
}

fclose($server);

/**
 * @return array{0: string, 1: string, 2: string}|null
 */
function read_request(mixed $client): ?array
{
    $header = '';

    while (!str_contains($header, "\r\n\r\n")) {
        $chunk = fread($client, 8192);

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $header .= $chunk;
    }

    [$head, $rest] = explode("\r\n\r\n", $header, 2);
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines) ?? '';

    if (preg_match('#^(\S+)\s+(\S+)\s+HTTP/#', $requestLine, $matches) !== 1) {
        return null;
    }

    $length = 0;

    foreach ($lines as $line) {
        if (stripos($line, 'content-length:') === 0) {
            $length = (int) trim(substr($line, strlen('content-length:')));
        }
    }

    $body = $rest;

    while (strlen($body) < $length) {
        $chunk = fread($client, $length - strlen($body));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    $path = (string) parse_url($matches[2], PHP_URL_PATH);

    return [strtoupper($matches[1]), $path === '' ? '/' : $path, $body];
}

/**
 * @return array{0: int, 1: array<string, mixed>}
 */
function handle(string $method, string $path, string $body, RequestContext $context): array
{
    if ($path === '/context' && $method === 'GET') {
        // No per-request setup: whatever the last request wrote is still here.
        return [200, ['user' => $context->user()]];
    }

    if ($path === '/context' && $method === 'POST') {
        $payload = json_decode($body, true);

        if (!is_array($payload) || !isset($payload['user']) || !is_string($payload['user'])) {
            return [400, ['error' => 'expected {"user": "<name>"}']];
        }

        $context->setUser($payload['user']);

        return [200, ['ok' => true, 'user' => $context->user()]];
    }

    if ($path === '/reset' && $method === 'POST') {
        $context->forget();

        return [200, ['ok' => true]];
    }

    return [404, ['error' => 'not found']];
}

/**
 * @param array<string, mixed> $payload
 */
function respond(mixed $client, int $status, array $payload): void
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

    $reason = match ($status) {
        200 => 'OK',
        400 => 'Bad Request',
        404 => 'Not Found',
        default => 'OK',
    };

    fwrite($client, sprintf(
        "HTTP/1.1 %d %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nX-Worker-Pid: %d\r\nConnection: close\r\n\r\n%s",
        $status,
        $reason,
        strlen($body),
        getmypid(),
        $body,
    ));
}
