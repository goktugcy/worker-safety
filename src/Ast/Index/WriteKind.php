<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

/**
 * How a piece of persistent state was mutated.
 */
enum WriteKind: string
{
    /** `self::$x = $value` with a value that is not obviously empty. */
    case Assign = 'assign';

    /** `self::$x[] = $value` — unbounded growth. */
    case Append = 'append';

    /** `self::$x[$key] = $value` — growth bounded only by the key space. */
    case KeyedWrite = 'keyed-write';

    /** `self::$x .= …`, `self::$x += …` */
    case Compound = 'compound';

    /** `self::$x++`, `--self::$x` */
    case IncDec = 'inc-dec';

    /** `$ref = &self::$x` — the value escapes and can be mutated elsewhere. */
    case Reference = 'reference';

    /** `self::$x = []`, `self::$x = null` — releases the retained value. */
    case Clear = 'clear';

    /** `unset(self::$x)`, `unset(self::$x[$key])` */
    case Unset = 'unset';

    /** `array_shift(self::$x)`, `array_pop(self::$x)`, `array_splice(...)` */
    case Shrink = 'shrink';

    /** `array_push(self::$x, …)`, `array_unshift(self::$x, …)` */
    case Grow = 'grow';

    public function isClearing(): bool
    {
        return $this === self::Clear || $this === self::Unset || $this === self::Shrink;
    }

    /**
     * True when the operation empties the collection rather than removing one
     * entry from it.
     */
    public function isFullRelease(): bool
    {
        return $this === self::Clear || $this === self::Unset;
    }

    public function isGrowth(): bool
    {
        return $this === self::Append || $this === self::KeyedWrite || $this === self::Grow;
    }
}
