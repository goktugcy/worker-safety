<?php

declare(strict_types=1);

namespace WorkerSafety\Finding;

/**
 * Where a finding was produced.
 *
 * Deliberately free of any AST types so runtime analyzers can build one too.
 */
final class Location
{
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly int $line,
        public readonly ?int $column = null,
        public readonly ?int $endLine = null,
    ) {
    }

    public function __toString(): string
    {
        return $this->relativePath . ':' . $this->line;
    }

    public function equals(self $other): bool
    {
        return $this->absolutePath === $other->absolutePath
            && $this->line === $other->line
            && $this->column === $other->column;
    }
}
