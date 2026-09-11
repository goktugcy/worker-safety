<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use WorkerSafety\Finding\Location;

/**
 * A single observed mutation of a static property or static local variable.
 */
final class StateWrite
{
    public function __construct(
        public readonly WriteKind $kind,
        public readonly Location $location,
        public readonly ?string $snippet = null,
        public readonly ?string $inClass = null,
        public readonly ?string $inMethod = null,
        public readonly bool $inConstructor = false,
        public readonly bool $inResetMethod = false,
        public readonly bool $literalKey = false,
        public readonly bool $inLoop = false,
        public readonly bool $guaranteed = true,
        /** @var list<string> collections whose size provably guards this write */
        public readonly array $sizeGuardedKeys = [],
        public readonly ?string $keyExpression = null,
    ) {
    }

    /**
     * True when this write only runs once the named collection has exceeded a
     * finite limit — the eviction half of a bounded cache.
     */
    public function boundsCollection(string $key): bool
    {
        return in_array($key, $this->sizeGuardedKeys, true);
    }

    /**
     * True when both writes address the same array key, so one provably undoes
     * the other.
     */
    public function targetsSameKeyAs(self $other): bool
    {
        return $this->keyExpression !== null && $this->keyExpression === $other->keyExpression;
    }

    /**
     * True when the write can add an unpredictable number of entries.
     *
     * `self::$x['last'] = …` always targets the same slot, so it grows the
     * array to a fixed size rather than without bound.
     */
    public function growsUnbounded(): bool
    {
        if (!$this->isGrowth()) {
            return false;
        }

        return !($this->kind === WriteKind::KeyedWrite && $this->literalKey);
    }

    public function isClearing(): bool
    {
        return $this->kind->isClearing();
    }

    public function isGrowth(): bool
    {
        return $this->kind->isGrowth();
    }
}
