<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Starts and stops the persistent fixture worker.
 *
 * The server prints its bound port on stdout once it is listening, so tests
 * neither guess a port (which collides in CI) nor poll blindly (which races).
 */
final class FixtureWorker
{
    /**
     * @var resource|null
     */
    private mixed $process = null;

    /**
     * @var array<int, resource>
     */
    private array $pipes = [];

    private function __construct(public readonly int $port)
    {
    }

    public static function start(float $idleTimeout = 30.0): self
    {
        return self::startScript('Replay/leaky-worker/server.php', $idleTimeout);
    }

    /**
     * The same worker with its context built per request — the control that
     * shows the replay engine reports a pass when there is nothing to observe.
     */
    public static function startFixed(float $idleTimeout = 30.0): self
    {
        return self::startScript('Replay/fixed-worker/server.php', $idleTimeout);
    }

    /**
     * A server that answers with deliberately awkward HTTP, for transport tests.
     */
    public static function startHttpIntegrity(float $idleTimeout = 30.0): self
    {
        return self::startScript('Replay/http-integrity/server.php', $idleTimeout);
    }

    public static function startScript(string $relativePath, float $idleTimeout = 30.0): self
    {
        $script = Fixtures::path($relativePath);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            [PHP_BINARY, $script, '0', (string) $idleTimeout],
            $descriptors,
            $pipes,
        );

        Assert::assertIsResource($process, 'Could not start the fixture worker.');

        /** @var array<int, resource> $pipes */
        stream_set_blocking($pipes[1], false);

        $port = self::awaitPort($pipes[1], $pipes[2]);

        $worker = new self($port);
        $worker->process = $process;
        $worker->pipes = $pipes;

        return $worker;
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_terminate($this->process);
        proc_close($this->process);

        $this->process = null;
        $this->pipes = [];
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    private static function awaitPort(mixed $stdout, mixed $stderr): int
    {
        $deadline = microtime(true) + 10.0;
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = fread($stdout, 1024);

            if (is_string($chunk) && $chunk !== '') {
                $buffer .= $chunk;

                if (preg_match('/^PORT=(\d+)$/m', $buffer, $matches) === 1) {
                    return (int) $matches[1];
                }
            }

            usleep(10_000);
        }

        $error = stream_get_contents($stderr);

        Assert::fail(sprintf(
            'The fixture worker did not report a port within 10s. stdout: %s stderr: %s',
            var_export($buffer, true),
            is_string($error) ? var_export($error, true) : '(none)',
        ));
    }
}
