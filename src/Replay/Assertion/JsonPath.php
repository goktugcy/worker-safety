<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Assertion;

use WorkerSafety\Replay\Result\MissingValue;

/**
 * Dot-path lookup and JSON-aware comparison.
 *
 * Deliberately not JSONPath: `user`, `tenant.id` and `items.0.id` cover what a
 * replay expectation needs, and every one of them is obvious at a glance. A
 * numeric segment indexes a list.
 *
 * Both sides of a comparison are decoded with JSON's own type model — objects
 * as stdClass, arrays as lists — because collapsing them into PHP associative
 * arrays loses two distinctions that matter: `{}` stops being different from
 * `[]`, and key order starts to matter when it should not.
 */
final class JsonPath
{
    private function __construct()
    {
    }

    public static function get(mixed $document, string $path): mixed
    {
        $current = $document;

        foreach (explode('.', $path) as $segment) {
            if ($segment === '') {
                return MissingValue::Instance;
            }

            if ($current instanceof \stdClass) {
                $properties = get_object_vars($current);

                if (!array_key_exists($segment, $properties)) {
                    return MissingValue::Instance;
                }

                $current = $properties[$segment];

                continue;
            }

            if (!is_array($current)) {
                return MissingValue::Instance;
            }

            if (array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }

            // A list index arrives as a string from the path.
            if (ctype_digit($segment) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];

                continue;
            }

            return MissingValue::Instance;
        }

        return $current;
    }

    /**
     * Recursive JSON equality.
     *
     *  - Objects compare by key set and value, never by key order: `{"a":1,"b":2}`
     *    and `{"b":2,"a":1}` are the same document.
     *  - Arrays compare element by element, in order: `[1,2]` is not `[2,1]`.
     *  - An object is never equal to an array, so `{}` and `[]` stay distinct.
     *  - Strings, booleans and numbers keep their types: `1`, `"1"` and `true`
     *    are three different values.
     *  - The one deliberate loosening is numeric: `1` equals `1.0`, because YAML
     *    and JSON disagree about integer versus float far more often than a user
     *    cares about. It is applied at every depth, not only at the top.
     */
    public static function equals(mixed $expected, mixed $actual): bool
    {
        if ($expected instanceof MissingValue || $actual instanceof MissingValue) {
            return $expected === $actual;
        }

        if (is_int($expected) && is_float($actual)) {
            return (float) $expected === $actual;
        }

        if (is_float($expected) && is_int($actual)) {
            return $expected === (float) $actual;
        }

        if ($expected instanceof \stdClass || $actual instanceof \stdClass) {
            return $expected instanceof \stdClass
                && $actual instanceof \stdClass
                && self::objectsEqual($expected, $actual);
        }

        if (is_array($expected) || is_array($actual)) {
            return is_array($expected) && is_array($actual) && self::arraysEqual($expected, $actual);
        }

        return $expected === $actual;
    }

    private static function objectsEqual(\stdClass $expected, \stdClass $actual): bool
    {
        $left = get_object_vars($expected);
        $right = get_object_vars($actual);

        if (count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            // array_key_exists, not isset: a property holding null is present.
            if (!array_key_exists($key, $right) || !self::equals($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    private static function arraysEqual(array $expected, array $actual): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }

        $left = array_values($expected);
        $right = array_values($actual);

        foreach ($left as $index => $value) {
            if (!self::equals($value, $right[$index])) {
                return false;
            }
        }

        return true;
    }
}
