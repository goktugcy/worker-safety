<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression\FactoryMemoization;

/**
 * Shapes that all memoize into a static property with `??=`.
 *
 * The point of this fixture is that the write-once shape is identical in every
 * one of them, while the risk is not: what separates them is where the stored
 * value comes from, and that is not something the analyzer can read off the
 * syntax. See FactoryMemoizationRegressionTest.
 */

/**
 * The factory Laravel ships in every new application, reproduced verbatim.
 */
final class StandardUserFactory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}

/**
 * Same syntax, request-specific value: the first request to reach this line
 * freezes its own user into every later request the worker serves.
 */
final class CurrentUserFactory
{
    protected static ?object $user;

    public function definition(): array
    {
        return [
            'owner' => static::$user ??= auth()->user(),
        ];
    }
}

/**
 * Same syntax, caller-supplied value: whichever caller wins the race decides
 * the password for the rest of the worker's life.
 */
final class VariablePasswordFactory
{
    protected static ?string $password;

    public function withPassword(string $password): array
    {
        return [
            'password' => static::$password ??= Hash::make($password),
        ];
    }
}

/**
 * Same syntax, request input: `request()` reads the live request.
 */
final class TenantFactory
{
    protected static ?string $tenant;

    public function definition(): array
    {
        return [
            'tenant' => static::$tenant ??= request('tenant'),
        ];
    }
}

/**
 * `??=` does not mean the initializer runs once: this one returns null, so the
 * slot is never filled and the call is repeated on every pass. Verified by
 * running it — three calls, three computations.
 */
final class NullReturningMemo
{
    private static ?string $value = null;

    public static function get(): ?string
    {
        return self::$value ??= self::compute();
    }

    private static function compute(): ?string
    {
        return null;
    }
}

/**
 * Nor does it mean the value survives: the reset immediately before it re-opens
 * the slot, so this also recomputes on every call. The reset is a clearing
 * write, which the rule excludes when it looks at the assignments, so this is
 * the shape that a "computed once" claim gets wrong.
 */
final class ResetBeforeMemo
{
    private static ?string $value = null;

    private static int $calls = 0;

    public static function get(): ?string
    {
        self::$value = null;

        return self::$value ??= 'v' . ++self::$calls;
    }
}

/**
 * Not conditional: a plain assignment replaces the value on every call.
 */
final class ReassignedFactory
{
    protected static ?string $password;

    public function definition(): array
    {
        static::$password = Hash::make('password');

        return ['password' => static::$password];
    }
}
