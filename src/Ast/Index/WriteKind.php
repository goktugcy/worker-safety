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

    /**
     * `self::$x ??= …` — assigns only while the slot is null or unset.
     *
     * Tracked apart from Compound because the reuse is conditional rather than
     * unconditional: a plain assignment replaces the value on every pass, while
     * this one writes only into an empty slot, so a non-null value already
     * there is reused instead.
     *
     * That is all it establishes. It is *not* evidence that the initializer
     * runs once: an initializer that yields null leaves the slot empty and runs
     * again on the next pass, and any reset — including one outside the scanned
     * paths — re-opens it. Nor does it say anything about whether the stored
     * value is request-specific.
     */
    case CoalesceAssign = 'coalesce-assign';

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

    /**
     * True when the operation writes only into an empty slot.
     *
     * A statement about the assignment, not about the value or how often it is
     * evaluated: reuse of a stored value is deliberate memoization when every
     * input is fixed, and a cross-request leak when it is not. Nothing in the
     * syntax separates the two.
     */
    public function isConditionalAssignment(): bool
    {
        return $this === self::CoalesceAssign;
    }
}
