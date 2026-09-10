<?php

declare(strict_types=1);

namespace WorkerSafety\Ignore;

/**
 * Per-file record of which rules are suppressed where.
 *
 * An empty rule list means "every rule".
 */
final class SuppressionIndex
{
    /**
     * @var array<int, list<string>>
     */
    private array $lines = [];

    /**
     * @var list<array{start: int, end: int, rules: list<string>}>
     */
    private array $ranges = [];

    /**
     * @var list<list<string>>
     */
    private array $fileWide = [];

    /**
     * @param list<string> $ruleIds
     */
    public function suppressLine(int $line, array $ruleIds): void
    {
        if ($line < 1) {
            return;
        }

        $existing = $this->lines[$line] ?? null;

        if ($existing === null) {
            $this->lines[$line] = $ruleIds;

            return;
        }

        // Once a line suppresses everything it keeps suppressing everything.
        if ($existing === [] || $ruleIds === []) {
            $this->lines[$line] = [];

            return;
        }

        $this->lines[$line] = array_values(array_unique([...$existing, ...$ruleIds]));
    }

    /**
     * @param list<string> $ruleIds
     */
    public function suppressRange(int $start, int $end, array $ruleIds): void
    {
        if ($start < 1 || $end < $start) {
            return;
        }

        $this->ranges[] = ['start' => $start, 'end' => $end, 'rules' => $ruleIds];
    }

    /**
     * @param list<string> $ruleIds
     */
    public function suppressFile(array $ruleIds): void
    {
        $this->fileWide[] = $ruleIds;
    }

    public function isSuppressed(string $ruleId, int $line): bool
    {
        $ruleId = strtoupper($ruleId);

        foreach ($this->fileWide as $rules) {
            if ($this->covers($rules, $ruleId)) {
                return true;
            }
        }

        if (isset($this->lines[$line]) && $this->covers($this->lines[$line], $ruleId)) {
            return true;
        }

        foreach ($this->ranges as $range) {
            if ($line >= $range['start'] && $line <= $range['end'] && $this->covers($range['rules'], $ruleId)) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [] && $this->ranges === [] && $this->fileWide === [];
    }

    /**
     * @param list<string> $rules
     */
    private function covers(array $rules, string $ruleId): bool
    {
        return $rules === [] || in_array($ruleId, $rules, true);
    }
}
