<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression\FactoryMemoizationIgnored;

/**
 * The documented way to keep the stock Laravel factory out of a build.
 *
 * The hash of a fixed literal is the same for every request, so the
 * memoization is deliberate; the directive records that decision next to the
 * code instead of hiding it in the analyzer.
 */
final class StandardUserFactory
{
    // worker-safety-ignore WS001 memoized hash of a constant test password
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
        ];
    }
}
