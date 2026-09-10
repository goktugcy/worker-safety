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
     * Whitespace-normalized source of a line range.
     *
     * Used as the identity of a finding: a single display line is not enough,
     * because `putenv(` looks the same no matter what argument follows it on
     * the next line.
     */
    public function excerpt(int $startLine, ?int $endLine = null, int $maxLines = 20): ?string
    {
        $endLine = max($startLine, $endLine ?? $startLine);
        $endLine = min($endLine, $startLine + $maxLines - 1);

        $parts = [];

        for ($line = $startLine; $line <= $endLine; ++$line) {
            $source = $this->line($line);

            if ($source === null) {
                break;
            }

            $parts[] = trim($source);
        }

        $joined = trim(implode(' ', $parts));
        $collapsed = preg_replace('/\s+/', ' ', $joined);

        $result = trim($collapsed ?? $joined);

        return $result === '' ? null : $result;
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
