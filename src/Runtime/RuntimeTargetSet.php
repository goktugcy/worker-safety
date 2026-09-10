<?php

declare(strict_types=1);

namespace WorkerSafety\Runtime;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Immutable, de-duplicated, canonically ordered set of runtime targets.
 *
 * @implements IteratorAggregate<int, RuntimeTarget>
 */
final class RuntimeTargetSet implements Countable, IteratorAggregate
{
    /**
     * @var list<RuntimeTarget>
     */
    private readonly array $targets;

    /**
     * @param iterable<RuntimeTarget> $targets
     */
    public function __construct(iterable $targets)
    {
        $seen = [];

        foreach ($targets as $target) {
            $seen[$target->value] = true;
        }

        // Keep the declaration order of the enum for stable output.
        $ordered = [];

        foreach (RuntimeTarget::cases() as $case) {
            if (isset($seen[$case->value])) {
                $ordered[] = $case;
            }
        }

        $this->targets = $ordered;
    }

    public static function all(): self
    {
        return new self(RuntimeTarget::all());
    }

    /**
     * @param list<string> $values
     */
    public static function fromStrings(array $values): self
    {
        $targets = [];

        foreach ($values as $value) {
            if (strtolower(trim($value)) === 'all') {
                return self::all();
            }

            $targets[] = RuntimeTarget::fromString($value);
        }

        return new self($targets);
    }

    public function contains(RuntimeTarget $target): bool
    {
        return in_array($target, $this->targets, true);
    }

    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    public function isAll(): bool
    {
        return count($this->targets) === count(RuntimeTarget::cases());
    }

    /**
     * @param iterable<RuntimeTarget> $other
     */
    public function intersect(iterable $other): self
    {
        $result = [];

        foreach ($other as $target) {
            if ($this->contains($target)) {
                $result[] = $target;
            }
        }

        return new self($result);
    }

    /**
     * @return list<RuntimeTarget>
     */
    public function toArray(): array
    {
        return $this->targets;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(static fn (RuntimeTarget $target): string => $target->value, $this->targets);
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return array_map(static fn (RuntimeTarget $target): string => $target->label(), $this->targets);
    }

    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'none';
        }

        return implode(', ', $this->labels());
    }

    public function count(): int
    {
        return count($this->targets);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->targets);
    }
}
