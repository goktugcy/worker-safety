<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Support\ExcludeMatcher;

#[CoversClass(ExcludeMatcher::class)]
final class ExcludeMatcherTest extends TestCase
{
    public function test_a_bare_name_matches_any_path_segment(): void
    {
        $matcher = new ExcludeMatcher(['vendor']);

        self::assertTrue($matcher->matches('vendor/acme/src/A.php'));
        self::assertTrue($matcher->matches('app/vendor/A.php'));
        self::assertFalse($matcher->matches('app/vendors/A.php'));
        self::assertFalse($matcher->matches('app/MyVendor.php'));
    }

    public function test_a_pattern_with_a_slash_matches_a_prefix(): void
    {
        $matcher = new ExcludeMatcher(['bootstrap/cache']);

        self::assertTrue($matcher->matches('bootstrap/cache/config.php'));
        self::assertTrue($matcher->matches('bootstrap/cache'));
        self::assertFalse($matcher->matches('app/bootstrap/cache/x.php'));
        self::assertFalse($matcher->matches('bootstrap/app.php'));
    }

    public function test_globs_match_the_path_and_the_basename(): void
    {
        $matcher = new ExcludeMatcher(['*.blade.php']);

        self::assertTrue($matcher->matches('resources/views/home.blade.php'));
        self::assertFalse($matcher->matches('app/Home.php'));
    }

    public function test_leading_and_trailing_slashes_are_ignored(): void
    {
        $matcher = new ExcludeMatcher(['/storage/']);

        self::assertTrue($matcher->matches('storage/logs/laravel.log'));
    }

    public function test_an_empty_matcher_matches_nothing(): void
    {
        $matcher = new ExcludeMatcher([]);

        self::assertTrue($matcher->isEmpty());
        self::assertFalse($matcher->matches('vendor/a.php'));
    }

    public function test_blank_patterns_are_dropped(): void
    {
        self::assertTrue((new ExcludeMatcher(['', '  ', '/']))->isEmpty());
    }
}
