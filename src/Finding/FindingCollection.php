<?php

declare(strict_types=1);

namespace WorkerSafety\Finding;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Immutable, de-duplicated list of findings.
 *
 * @implements IteratorAggregate<int, Finding>
 */
final class FindingCollection implements Countable, IteratorAggregate
{
    /**
     * @var list<Finding>
     */
    private readonly array $findings;

    /**
     * @param iterable<Finding> $findings
     */
    public function __construct(iterable $findings = [])
    {
        $unique = [];

        foreach ($findings as $finding) {
            $unique[$finding->dedupeKey()] = $finding;
        }

        $this->findings = array_values($unique);
    }

    /**
     * @param iterable<Finding> $findings
     */
    public function merge(iterable $findings): self
    {
        $merged = $this->findings;

        foreach ($findings as $finding) {
            $merged[] = $finding;
        }

        return new self($merged);
    }

    /**
     * Canonical report order: severity desc, then file, then line, then rule id.
     */
    public function sorted(): self
    {
        $sorted = $this->findings;

        usort($sorted, static function (Finding $a, Finding $b): int {
            return $b->severity->rank() <=> $a->severity->rank()
                ?: strcmp($a->location->relativePath, $b->location->relativePath)
                ?: $a->location->line <=> $b->location->line
                ?: ($a->location->column ?? 0) <=> ($b->location->column ?? 0)
                ?: strcmp($a->ruleId, $b->ruleId);
        });

        return new self($sorted);
    }

    /**
     * @param callable(Finding): bool $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_filter($this->findings, $predicate));
    }

    public function withSeverityAtLeast(Severity $threshold): self
    {
        return $this->filter(static fn (Finding $finding): bool => $finding->severity->isAtLeast($threshold));
    }

    public function hasSeverityAtLeast(Severity $threshold): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity->isAtLeast($threshold)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int> keyed by severity value, always contains every severity
     */
    public function countsBySeverity(): array
    {
        $counts = [];

        foreach (Severity::ordered() as $severity) {
            $counts[$severity->value] = 0;
        }

        foreach ($this->findings as $finding) {
            ++$counts[$finding->severity->value];
        }

        return $counts;
    }

    /**
     * @return array<string, int> keyed by rule id, ordered by rule id
     */
    public function countsByRule(): array
    {
        $counts = [];

        foreach ($this->findings as $finding) {
            $counts[$finding->ruleId] = ($counts[$finding->ruleId] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<Finding>
     */
    public function toArray(): array
    {
        return $this->findings;
    }

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    public function count(): int
    {
        return count($this->findings);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->findings);
    }
}
