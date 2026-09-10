<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Runtime\RuntimeTarget;
use WorkerSafety\Runtime\RuntimeTargetSet;

#[CoversClass(RuntimeTarget::class)]
#[CoversClass(RuntimeTargetSet::class)]
final class RuntimeTargetSetTest extends TestCase
{
    #[DataProvider('aliases')]
    public function test_runtime_names_and_aliases(string $input, RuntimeTarget $expected): void
    {
        self::assertSame($expected, RuntimeTarget::fromString($input));
    }

    /**
     * @return iterable<string, array{string, RuntimeTarget}>
     */
    public static function aliases(): iterable
    {
        yield 'frankenphp' => ['frankenphp', RuntimeTarget::FrankenPhp];
        yield 'FrankenPHP' => ['FrankenPHP', RuntimeTarget::FrankenPhp];
        yield 'franken-php' => ['franken-php', RuntimeTarget::FrankenPhp];
        yield 'rr' => ['rr', RuntimeTarget::RoadRunner];
        yield 'road-runner' => ['road-runner', RuntimeTarget::RoadRunner];
        yield 'laravel-octane' => ['laravel-octane', RuntimeTarget::Octane];
        yield 'openswoole' => ['openswoole', RuntimeTarget::Swoole];
    }

    public function test_an_unknown_runtime_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);

        RuntimeTarget::fromString('fpm');
    }

    public function test_labels_are_human_readable(): void
    {
        self::assertSame('FrankenPHP', RuntimeTarget::FrankenPhp->label());
        self::assertSame('RoadRunner', RuntimeTarget::RoadRunner->label());
    }

    public function test_every_runtime_carries_a_note(): void
    {
        foreach (RuntimeTarget::all() as $runtime) {
            self::assertNotSame('', $runtime->note());
        }
    }

    public function test_the_set_is_deduplicated_and_ordered_by_the_enum(): void
    {
        $set = new RuntimeTargetSet([
            RuntimeTarget::Swoole,
            RuntimeTarget::FrankenPhp,
            RuntimeTarget::Swoole,
        ]);

        self::assertSame(['frankenphp', 'swoole'], $set->values());
        self::assertCount(2, $set);
    }

    public function test_all_and_is_all(): void
    {
        self::assertTrue(RuntimeTargetSet::all()->isAll());
        self::assertFalse((new RuntimeTargetSet([RuntimeTarget::Octane]))->isAll());
    }

    public function test_from_strings_supports_all(): void
    {
        self::assertTrue(RuntimeTargetSet::fromStrings(['octane', 'all'])->isAll());
    }

    public function test_intersect(): void
    {
        $selected = new RuntimeTargetSet([RuntimeTarget::FrankenPhp, RuntimeTarget::Octane]);

        self::assertSame(['octane'], $selected->intersect([RuntimeTarget::Octane, RuntimeTarget::Swoole])->values());
        self::assertTrue($selected->intersect([RuntimeTarget::Swoole])->isEmpty());
    }

    public function test_describe(): void
    {
        self::assertSame('FrankenPHP, Octane', (new RuntimeTargetSet([
            RuntimeTarget::Octane,
            RuntimeTarget::FrankenPhp,
        ]))->describe());
        self::assertSame('none', (new RuntimeTargetSet([]))->describe());
    }

    public function test_it_is_iterable(): void
    {
        $seen = [];

        foreach (new RuntimeTargetSet([RuntimeTarget::Octane]) as $runtime) {
            $seen[] = $runtime->value;
        }

        self::assertSame(['octane'], $seen);
    }
}
