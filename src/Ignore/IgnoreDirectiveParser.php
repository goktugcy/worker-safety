<?php

declare(strict_types=1);

namespace WorkerSafety\Ignore;

use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Support\SourceFile;

/**
 * Extracts inline suppression comments from PHP source.
 *
 * Supported forms (rule list optional; omitting it suppresses every rule):
 *
 *   // worker-safety-ignore WS001            same line and the following line
 *   // worker-safety-ignore-line WS001       only the comment's own line
 *   // worker-safety-ignore-next-line WS001  only the following line
 *   // worker-safety-ignore-file WS001,WS008 the whole file
 *
 * Comments are read from the token stream, so a marker inside a string
 * literal is never mistaken for a directive.
 */
final class IgnoreDirectiveParser
{
    public function parse(SourceFile $file): SuppressionIndex
    {
        $index = new SuppressionIndex();
        $marker = ApplicationInfo::IGNORE_MARKER;

        if (!str_contains($file->source, $marker)) {
            return $index;
        }

        foreach ($this->comments($file->source) as [$text, $startLine]) {
            $endLine = $startLine + substr_count($text, "\n");

            foreach ($this->directives($text) as [$scope, $ruleIds]) {
                match ($scope) {
                    'file' => $index->suppressFile($ruleIds),
                    'line' => $index->suppressLine($startLine, $ruleIds),
                    'next-line' => $index->suppressLine($endLine + 1, $ruleIds),
                    default => $this->suppressLineAndNext($index, $startLine, $endLine, $ruleIds),
                };
            }
        }

        return $index;
    }

    /**
     * @param list<string> $ruleIds
     */
    private function suppressLineAndNext(SuppressionIndex $index, int $startLine, int $endLine, array $ruleIds): void
    {
        // The bare directive is accepted both as a trailing comment and as a
        // comment placed above the offending line.
        $index->suppressLine($startLine, $ruleIds);
        $index->suppressLine($endLine + 1, $ruleIds);
    }

    /**
     * @return list<array{0: string, 1: list<string>}> scope and rule ids
     */
    private function directives(string $comment): array
    {
        $marker = preg_quote(ApplicationInfo::IGNORE_MARKER, '/');
        // Rule ids may be separated by spaces, commas or both.
        $pattern = '/(?:@)?' . $marker . '(-file|-line|-next-line)?((?:[ \t,]+[A-Za-z]{2}\d{3})*)/i';

        if (preg_match_all($pattern, $comment, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $directives = [];

        foreach ($matches as $match) {
            $scope = match (strtolower($match[1] ?? '')) {
                '-file' => 'file',
                '-line' => 'line',
                '-next-line' => 'next-line',
                default => 'both',
            };

            $directives[] = [$scope, $this->ruleIds($match[2] ?? '')];
        }

        return $directives;
    }

    /**
     * @return list<string>
     */
    private function ruleIds(string $raw): array
    {
        if (preg_match_all('/[A-Za-z]{2}\d{3}/', $raw, $matches) === false) {
            return [];
        }

        return array_values(array_unique(array_map('strtoupper', $matches[0])));
    }

    /**
     * @return list<array{0: string, 1: int}> comment text and start line
     */
    private function comments(string $source): array
    {
        $comments = [];

        foreach (@token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                continue;
            }

            $comments[] = [$token[1], $token[2]];
        }

        return $comments;
    }
}
