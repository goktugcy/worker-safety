<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Support\Paths;

#[CoversClass(Paths::class)]
final class PathsTest extends TestCase
{
    #[DataProvider('normalizations')]
    public function test_normalize(string $input, string $expected): void
    {
        self::assertSame($expected, Paths::normalize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizations(): iterable
    {
        yield 'absolute' => ['/a/b/c', '/a/b/c'];
        yield 'trailing slash' => ['/a/b/', '/a/b'];
        yield 'double slash' => ['/a//b', '/a/b'];
        yield 'dot' => ['/a/./b', '/a/b'];
        yield 'dotdot' => ['/a/b/../c', '/a/c'];
        yield 'dotdot at root' => ['/../a', '/a'];
        yield 'backslashes' => ['C:\\a\\b', 'C:/a/b'];
        yield 'relative' => ['a/b', 'a/b'];
        yield 'empty relative' => ['.', '.'];
    }

    public function test_is_absolute(): void
    {
        self::assertTrue(Paths::isAbsolute('/a'));
        self::assertTrue(Paths::isAbsolute('C:\\a'));
        self::assertFalse(Paths::isAbsolute('a/b'));
    }

    public function test_make_absolute_leaves_absolute_paths_alone(): void
    {
        self::assertSame('/a/b', Paths::makeAbsolute('/a/b', '/project'));
    }

    public function test_make_absolute_resolves_against_the_working_directory(): void
    {
        self::assertSame('/project/app', Paths::makeAbsolute('app', '/project'));
        self::assertSame('/project/app', Paths::makeAbsolute('./app', '/project'));
        self::assertSame('/app', Paths::makeAbsolute('../app', '/project'));
    }

    #[DataProvider('relativePaths')]
    public function test_make_relative(string $path, string $base, string $expected): void
    {
        self::assertSame($expected, Paths::makeRelative($path, $base));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function relativePaths(): iterable
    {
        yield 'inside' => ['/project/app/X.php', '/project', 'app/X.php'];
        yield 'same' => ['/project', '/project', '.'];
        yield 'trailing base slash' => ['/project/app', '/project/', 'app'];
        yield 'outside' => ['/other/app', '/project', '/other/app'];
        yield 'sibling prefix' => ['/projectile/app', '/project', '/projectile/app'];
    }

    public function test_segments(): void
    {
        self::assertSame(['a', 'b', 'c'], Paths::segments('/a/b/c'));
        self::assertSame([], Paths::segments('/'));
    }
}
