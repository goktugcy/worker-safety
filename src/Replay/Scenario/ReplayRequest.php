<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Scenario;

/**
 * One HTTP request a scenario step sends.
 */
final class ReplayRequest
{
    /**
     * @var list<string>
     */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<string, string> $headers
     * @param mixed $json decoded JSON body, or null when there is none
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers = [],
        public readonly mixed $json = null,
        public readonly ?string $body = null,
        public readonly bool $hasJson = false,
    ) {
    }

    public function isJson(): bool
    {
        return $this->hasJson;
    }

    /**
     * The bytes to send, or null for a request without a body.
     */
    public function payload(): ?string
    {
        if ($this->hasJson) {
            return json_encode($this->json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $this->body;
    }

    public function describe(): string
    {
        return $this->method . ' ' . $this->path;
    }
}
