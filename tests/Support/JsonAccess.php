<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Typed access into decoded JSON.
 *
 * `json_decode()` hands back `mixed`, so reaching into a report with
 * `$decoded['summary']['high']` gives static analysis nothing to work with.
 * These helpers assert the shape as they walk it, which keeps the tests both
 * type-safe and strict about the schema.
 */
trait JsonAccess
{
    /**
     * @return array<string, mixed>
     */
    protected static function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        Assert::assertIsArray($decoded, 'Expected the payload to decode to an array.');

        $result = [];

        foreach ($decoded as $key => $value) {
            Assert::assertIsString($key, 'Expected an object at the JSON root.');
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decodeJsonFile(string $path): array
    {
        $contents = file_get_contents($path);

        Assert::assertIsString($contents, sprintf('Could not read %s', $path));

        return self::decodeJson($contents);
    }

    /**
     * Walk a path of keys/indexes, e.g. `at($report, 'summary', 'high')`.
     *
     * @param array<array-key, mixed> $data
     */
    protected static function at(array $data, string|int ...$path): mixed
    {
        $current = $data;
        $walked = [];

        foreach ($path as $key) {
            $walked[] = (string) $key;

            Assert::assertIsArray($current, sprintf('Expected an array at "%s".', implode('.', $walked)));
            Assert::assertArrayHasKey($key, $current, sprintf('Missing key "%s".', implode('.', $walked)));

            $current = $current[$key];
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    protected static function stringAt(array $data, string|int ...$path): string
    {
        $value = self::at($data, ...$path);

        Assert::assertIsString($value, sprintf('Expected a string at "%s".', implode('.', $path)));

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    protected static function intAt(array $data, string|int ...$path): int
    {
        $value = self::at($data, ...$path);

        Assert::assertIsInt($value, sprintf('Expected an int at "%s".', implode('.', $path)));

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    protected static function boolAt(array $data, string|int ...$path): bool
    {
        $value = self::at($data, ...$path);

        Assert::assertIsBool($value, sprintf('Expected a bool at "%s".', implode('.', $path)));

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    protected static function arrayAt(array $data, string|int ...$path): array
    {
        $value = self::at($data, ...$path);

        Assert::assertIsArray($value, sprintf('Expected an array at "%s".', implode('.', $path)));

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    protected static function keysAt(array $data, string|int ...$path): array
    {
        return array_map(
            static fn (string|int $key): string => (string) $key,
            array_keys(self::arrayAt($data, ...$path)),
        );
    }
}
