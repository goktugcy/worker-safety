<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Ignore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Ignore\IgnoreDirectiveParser;
use WorkerSafety\Ignore\SuppressionIndex;
use WorkerSafety\Support\SourceFile;

#[CoversClass(IgnoreDirectiveParser::class)]
#[CoversClass(SuppressionIndex::class)]
final class IgnoreDirectiveParserTest extends TestCase
{
    private function parse(string $source): SuppressionIndex
    {
        return (new IgnoreDirectiveParser())->parse(
            new SourceFile('/project/src/A.php', 'src/A.php', $source),
        );
    }

    public function test_no_marker_means_no_suppressions(): void
    {
        self::assertTrue($this->parse("<?php\n\$a = 1;\n")->isEmpty());
    }

    public function test_a_trailing_directive_suppresses_its_own_line(): void
    {
        $index = $this->parse("<?php\n\$a = 1; // worker-safety-ignore WS001\n");

        self::assertTrue($index->isSuppressed('WS001', 2));
        self::assertFalse($index->isSuppressed('WS002', 2));
    }

    public function test_a_directive_above_the_code_suppresses_the_next_line(): void
    {
        $index = $this->parse("<?php\n// worker-safety-ignore WS001\n\$a = 1;\n");

        self::assertTrue($index->isSuppressed('WS001', 3));
    }

    public function test_multiple_rule_ids_can_be_comma_or_space_separated(): void
    {
        $index = $this->parse("<?php\n\$a = 1; // worker-safety-ignore WS001,WS008 WS002\n");

        self::assertTrue($index->isSuppressed('WS001', 2));
        self::assertTrue($index->isSuppressed('WS008', 2));
        self::assertTrue($index->isSuppressed('WS002', 2));
        self::assertFalse($index->isSuppressed('WS003', 2));
    }

    public function test_a_directive_without_rule_ids_suppresses_everything_on_the_line(): void
    {
        $index = $this->parse("<?php\n\$a = 1; // worker-safety-ignore\n");

        self::assertTrue($index->isSuppressed('WS001', 2));
        self::assertTrue($index->isSuppressed('WS010', 2));
    }

    public function test_ignore_line_only_covers_its_own_line(): void
    {
        $index = $this->parse("<?php\n// worker-safety-ignore-line WS001\n\$a = 1;\n");

        self::assertTrue($index->isSuppressed('WS001', 2));
        self::assertFalse($index->isSuppressed('WS001', 3));
    }

    public function test_ignore_next_line_only_covers_the_following_line(): void
    {
        $index = $this->parse("<?php\n// worker-safety-ignore-next-line WS001\n\$a = 1;\n");

        self::assertFalse($index->isSuppressed('WS001', 2));
        self::assertTrue($index->isSuppressed('WS001', 3));
    }

    public function test_ignore_file_covers_every_line(): void
    {
        $index = $this->parse("<?php\n// worker-safety-ignore-file WS001\n\$a = 1;\n\$b = 2;\n");

        self::assertTrue($index->isSuppressed('WS001', 1));
        self::assertTrue($index->isSuppressed('WS001', 999));
        self::assertFalse($index->isSuppressed('WS002', 1));
    }

    public function test_ignore_file_without_rule_ids_covers_every_rule(): void
    {
        $index = $this->parse("<?php\n// worker-safety-ignore-file\n");

        self::assertTrue($index->isSuppressed('WS007', 42));
    }

    public function test_a_docblock_annotation_form_is_supported(): void
    {
        $index = $this->parse("<?php\n/** @worker-safety-ignore-next-line WS001 */\n\$a = 1;\n");

        self::assertTrue($index->isSuppressed('WS001', 3));
    }

    public function test_a_multiline_comment_targets_the_line_after_its_end(): void
    {
        $index = $this->parse("<?php\n/*\n * worker-safety-ignore WS001\n */\n\$a = 1;\n");

        self::assertTrue($index->isSuppressed('WS001', 5));
    }

    public function test_the_marker_inside_a_string_literal_is_not_a_directive(): void
    {
        $index = $this->parse("<?php\n\$a = 'worker-safety-ignore WS001';\n");

        self::assertTrue($index->isEmpty());
        self::assertFalse($index->isSuppressed('WS001', 2));
    }

    public function test_rule_ids_are_matched_case_insensitively(): void
    {
        $index = $this->parse("<?php\n\$a = 1; // worker-safety-ignore ws001\n");

        self::assertTrue($index->isSuppressed('WS001', 2));
    }

    public function test_suppressions_on_the_same_line_are_merged(): void
    {
        $index = new SuppressionIndex();
        $index->suppressLine(4, ['WS001']);
        $index->suppressLine(4, ['WS002']);

        self::assertTrue($index->isSuppressed('WS001', 4));
        self::assertTrue($index->isSuppressed('WS002', 4));
    }

    public function test_a_catch_all_suppression_wins_over_a_specific_one(): void
    {
        $index = new SuppressionIndex();
        $index->suppressLine(4, ['WS001']);
        $index->suppressLine(4, []);

        self::assertTrue($index->isSuppressed('WS009', 4));
    }

    public function test_invalid_ranges_are_ignored(): void
    {
        $index = new SuppressionIndex();
        $index->suppressLine(0, []);
        $index->suppressRange(5, 2, []);

        self::assertTrue($index->isEmpty(), 'Nonsensical ranges must not register a suppression.');
        self::assertFalse($index->isSuppressed('WS001', 3));
    }
}
