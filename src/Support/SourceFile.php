<?php

declare(strict_types=1);

namespace WorkerSafety\Support;

/**
 * A PHP file selected for analysis, together with its source.
 */
final class SourceFile
{
    /**
     * @var list<string>|null
     */
    private ?array $lines = null;

    /**
     * @var list<array{0: int, 1: int|null, 2: string}>|null
     */
    private ?array $tokens = null;

    public function __construct(
        public readonly string $absolutePath,
        public readonly string $relativePath,
        public readonly string $source,
    ) {
    }

    public static function fromPath(string $absolutePath, string $projectRoot): self
    {
        $source = @file_get_contents($absolutePath);

        return new self(
            $absolutePath,
            Paths::makeRelative($absolutePath, $projectRoot),
            $source === false ? '' : $source,
        );
    }

    /**
     * 1-indexed source line without the trailing newline, or null when out of range.
     */
    public function line(int $number): ?string
    {
        $lines = $this->lines();

        return $lines[$number - 1] ?? null;
    }

    /**
     * Trimmed single line snippet suitable for reports.
     */
    public function snippet(int $line): ?string
    {
        $source = $this->line($line);

        if ($source === null) {
            return null;
        }

        $trimmed = trim($source);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Canonical source of a byte range, used as the identity of a finding.
     *
     * Walks the token stream rather than the raw text so that the result is
     * stable under reformatting but exact about content:
     *
     *  - runs of whitespace *between* tokens collapse to a single space, so
     *    re-indenting a file does not invalidate a baseline;
     *  - the text of every other token, string literals and heredoc bodies
     *    included, is kept byte for byte, so `'a  b'` and `'a b'` stay
     *    distinct;
     *  - comments are dropped, so annotating code does not change its identity.
     *
     * There is deliberately no length limit: truncating the identity is what
     * let two different calls share a fingerprint.
     */
    public function identitySource(int $startFilePos, int $endFilePos): ?string
    {
        if ($startFilePos < 0 || $endFilePos < $startFilePos) {
            return null;
        }

        $parts = [];

        foreach ($this->tokens() as [$offset, $id, $text]) {
            if ($offset + strlen($text) - 1 < $startFilePos) {
                continue;
            }

            if ($offset > $endFilePos) {
                break;
            }

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            $parts[] = $id === T_WHITESPACE ? ' ' : $text;
        }

        $result = trim(implode('', $parts));

        return $result === '' ? null : $result;
    }

    /**
     * Lazily tokenized source as `[byte offset, token id or null, text]`.
     *
     * @return list<array{0: int, 1: int|null, 2: string}>
     */
    private function tokens(): array
    {
        if ($this->tokens !== null) {
            return $this->tokens;
        }

        $tokens = [];
        $offset = 0;

        foreach (@token_get_all($this->source) as $token) {
            if (is_array($token)) {
                $tokens[] = [$offset, $token[0], $token[1]];
                $offset += strlen($token[1]);

                continue;
            }

            $tokens[] = [$offset, null, $token];
            $offset += strlen($token);
        }

        return $this->tokens = $tokens;
    }

    public function lineCount(): int
    {
        return count($this->lines());
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->lines === null) {
            $this->lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $this->source));
        }

        return $this->lines;
    }
}
