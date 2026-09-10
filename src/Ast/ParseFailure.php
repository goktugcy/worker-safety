<?php

declare(strict_types=1);

namespace WorkerSafety\Ast;

/**
 * A syntax error encountered while parsing one file.
 *
 * Parse failures never abort a scan; they are collected and reported.
 */
final class ParseFailure
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $absolutePath,
        public readonly int $line,
        public readonly string $message,
        public readonly bool $fatal,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s:%d %s', $this->relativePath, $this->line, $this->message);
    }
}
