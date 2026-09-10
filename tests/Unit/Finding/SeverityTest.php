<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Finding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Finding\Severity;

#[CoversClass(Severity::class)]
final class SeverityTest extends TestCase
{
    public function test_ranking_is_strictly_increasing(): void
    {
        $ranks = array_map(
            static fn (Severity $severity): int => $severity->rank(),
            [Severity::Info, Severity::Low, Severity::Medium, Severity::High, Severity::Critical],
        );

        self::assertSame([0, 1, 2, 3, 4], $ranks);
    }

    public function test_ordered_is_most_severe_first(): void
    {
        self::assertSame(
            ['critical', 'high', 'medium', 'low', 'info'],
            array_map(static fn (Severity $s): string => $s->value, Severity::ordered()),
        );
    }

    public function test_is_at_least(): void
    {
        self::assertTrue(Severity::Critical->isAtLeast(Severity::High));
        self::assertTrue(Severity::High->isAtLeast(Severity::High));
        self::assertFalse(Severity::Medium->isAtLeast(Severity::High));
    }

    public function test_labels_are_upper_case(): void
    {
        self::assertSame('HIGH', Severity::High->label());
    }

    #[DataProvider('sarifLevels')]
    public function test_sarif_levels(Severity $severity, string $expected): void
    {
        self::assertSame($expected, $severity->sarifLevel());
    }

    /**
     * @return iterable<string, array{Severity, string}>
     */
    public static function sarifLevels(): iterable
    {
        yield 'critical' => [Severity::Critical, 'error'];
        yield 'high' => [Severity::High, 'error'];
        yield 'medium' => [Severity::Medium, 'warning'];
        yield 'low' => [Severity::Low, 'note'];
        yield 'info' => [Severity::Info, 'note'];
    }

    public function test_from_string_is_case_insensitive_and_trims(): void
    {
        self::assertSame(Severity::High, Severity::fromString('  HIGH '));
    }

    public function test_from_string_rejects_unknown_values(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/is not a known severity/');

        Severity::fromString('catastrophic');
    }

    public function test_try_from_string_returns_null_for_unknown_values(): void
    {
        self::assertNull(Severity::tryFromString('catastrophic'));
    }
}
