<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Result;

/**
 * The outcome of one expectation against one response.
 *
 * Holds passing and failing outcomes alike: the JSON report lists every
 * assertion with its verdict, and `ReplayResult::failures()` filters.
 *
 * `expected` and `actual` are the values as observed, so a reader can see
 * `null` versus `"alice"` rather than a rendered sentence.
 */
final class AssertionResult
{
    /**
     * Marker for "the response had no value at this path", which is not the
     * same as a value of null — the distinction the leak case turns on.
     *
     * An enum case rather than a reserved string: a response can contain any
     * string at all, so no string can mean "absent" without colliding with
     * real data. Nothing json_decode() produces is ever identical to this.
     */
    public const MISSING = MissingValue::Instance;

    private function __construct(
        public readonly string $type,
        public readonly bool $passed,
        public readonly ?string $path = null,
        public readonly mixed $expected = null,
        public readonly mixed $actual = null,
        public readonly ?string $detail = null,
    ) {
    }

    public static function pass(string $type, ?string $path = null, mixed $expected = null, mixed $actual = null): self
    {
        return new self($type, true, $path, $expected, $actual);
    }

    public static function fail(
        string $type,
        ?string $path = null,
        mixed $expected = null,
        mixed $actual = null,
        ?string $detail = null,
    ): self {
        return new self($type, false, $path, $expected, $actual, $detail);
    }

    public function actualIsMissing(): bool
    {
        return $this->actual === self::MISSING;
    }

    /**
     * The label the reporters show, e.g. `json.user` or `status`.
     */
    public function label(): string
    {
        if ($this->path === null) {
            return $this->type;
        }

        return match ($this->type) {
            'json_equals' => 'json.' . $this->path,
            'header_equals' => 'header.' . $this->path,
            default => $this->type . '.' . $this->path,
        };
    }
}
