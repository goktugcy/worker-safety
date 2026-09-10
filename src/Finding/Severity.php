<?php

declare(strict_types=1);

namespace WorkerSafety\Finding;

use WorkerSafety\Exception\ConfigurationException;

enum Severity: string
{
    case Info = 'info';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Higher is more severe. Used for sorting and threshold comparison.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function label(): string
    {
        return strtoupper($this->value);
    }

    public function isAtLeast(self $threshold): bool
    {
        return $this->rank() >= $threshold->rank();
    }

    /**
     * SARIF 2.1.0 `result.level`.
     */
    public function sarifLevel(): string
    {
        return match ($this) {
            self::Critical, self::High => 'error',
            self::Medium => 'warning',
            self::Low, self::Info => 'note',
        };
    }

    /**
     * Symfony Console style tag used by the console reporter.
     */
    public function consoleStyle(): string
    {
        return match ($this) {
            self::Critical => 'ws-critical',
            self::High => 'ws-high',
            self::Medium => 'ws-medium',
            self::Low => 'ws-low',
            self::Info => 'ws-info',
        };
    }

    public static function fromString(string $value): self
    {
        $severity = self::tryFromString($value);

        if (!$severity instanceof self) {
            throw ConfigurationException::invalidValue(
                'severity',
                sprintf('"%s" is not a known severity (%s).', $value, implode(', ', self::names())),
            );
        }

        return $severity;
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    /**
     * Most severe first — the canonical report ordering.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Critical, self::High, self::Medium, self::Low, self::Info];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
