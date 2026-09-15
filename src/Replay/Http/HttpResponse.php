<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Http;

/**
 * One HTTP response, already read into memory.
 *
 * A non-2xx status is an ordinary response here, not an error: a scenario is
 * allowed to expect a 404, so only a response that never arrived is a failure.
 */
final class HttpResponse
{
    /**
     * Lower-cased header name => value, because HTTP header names are
     * case-insensitive and scenarios should not have to guess the casing.
     *
     * @var array<string, string>
     */
    private readonly array $normalizedHeaders;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $this->normalizedHeaders = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->normalizedHeaders[strtolower($name)] ?? null;
    }

    /**
     * The decoded body, or null when it is not JSON.
     *
     * Decoded *without* `assoc`, so JSON's own types survive: an object becomes
     * stdClass and an array becomes a list. Decoding to associative arrays
     * would merge the two, and `{}` would stop being distinguishable from `[]`
     * — which is a difference a response can legitimately turn on.
     *
     * Returning null rather than throwing keeps "the body was not JSON" a
     * reportable assertion failure instead of an aborted run.
     */
    public function json(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }

        try {
            return json_decode($this->body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * The decoded body as nested associative arrays.
     *
     * For callers that only want to read a value and do not care about the
     * object/array distinction. Assertions never use this.
     */
    public function jsonAsArray(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }

        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    public function bodyIsJson(): bool
    {
        if (trim($this->body) === '') {
            return false;
        }

        json_decode($this->body, true);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
