<?php

declare(strict_types=1);

namespace WorkerSafety\Finding;

use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * An immutable, transport agnostic analysis result.
 *
 * Static rules build these from AST nodes; a future runtime probe can build the
 * exact same object from an observed request, which is why nothing here refers
 * to php-parser.
 */
final class Finding
{
    /**
     * @param list<string> $remediation
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $title,
        public readonly Severity $severity,
        public readonly RuleCategory $category,
        public readonly Location $location,
        public readonly string $message,
        public readonly ?string $details = null,
        public readonly array $remediation = [],
        public readonly SymbolContext $symbol = new SymbolContext(),
        public readonly ?string $snippet = null,
        public readonly RuntimeTargetSet $runtimes = new RuntimeTargetSet([]),
        public readonly ?string $framework = null,
        public readonly ?string $excerpt = null,
    ) {
    }

    public function withSeverity(Severity $severity): self
    {
        if ($severity === $this->severity) {
            return $this;
        }

        return new self(
            $this->ruleId,
            $this->title,
            $severity,
            $this->category,
            $this->location,
            $this->message,
            $this->details,
            $this->remediation,
            $this->symbol,
            $this->snippet,
            $this->runtimes,
            $this->framework,
            $this->excerpt,
        );
    }

    public function withRuntimes(RuntimeTargetSet $runtimes): self
    {
        return new self(
            $this->ruleId,
            $this->title,
            $this->severity,
            $this->category,
            $this->location,
            $this->message,
            $this->details,
            $this->remediation,
            $this->symbol,
            $this->snippet,
            $runtimes,
            $this->framework,
            $this->excerpt,
        );
    }

    /**
     * Line independent identity, used by the baseline so that unrelated edits
     * above a finding do not resurrect it.
     */
    /**
     * Line independent identity, used by the baseline so that unrelated edits
     * above a finding do not resurrect it.
     *
     * The code component is the whole reported construct, not the single line
     * shown in reports and with no length limit: two calls that differ only on
     * a continuation line must not share an identity, or a baseline would
     * accept the changed one without review.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', implode("\0", [
            $this->ruleId,
            $this->location->relativePath,
            $this->symbol->describe() ?? '',
            // The excerpt is already canonical; collapsing it again would
            // erase whitespace that is meaningful inside a string literal.
            $this->excerpt ?? self::normalizeSnippet($this->snippet ?? $this->message),
        ])), 0, 32);
    }

    /**
     * Identity including the position, used to drop duplicate reports.
     */
    public function dedupeKey(): string
    {
        return implode("\0", [
            $this->ruleId,
            $this->location->absolutePath,
            (string) $this->location->line,
            (string) ($this->location->column ?? 0),
            $this->message,
        ]);
    }

    private static function normalizeSnippet(string $snippet): string
    {
        $normalized = preg_replace('/\s+/', ' ', $snippet);

        return trim($normalized ?? $snippet);
    }
}
