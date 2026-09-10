<?php

declare(strict_types=1);

namespace WorkerSafety\Ast;

use PhpParser\Node\Stmt;

/**
 * Outcome of parsing a single file.
 *
 * php-parser can recover from many syntax errors, so `statements` may be a
 * usable (partial) AST even when `failures` is not empty.
 */
final class ParseResult
{
    /**
     * @param list<Stmt> $statements
     * @param list<ParseFailure> $failures
     */
    public function __construct(
        public readonly array $statements,
        public readonly array $failures,
        public readonly bool $usable,
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }
}
