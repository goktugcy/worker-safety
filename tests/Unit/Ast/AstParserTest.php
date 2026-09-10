<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Ast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Ast\AstParser;
use WorkerSafety\Support\SourceFile;

#[CoversClass(AstParser::class)]
final class AstParserTest extends TestCase
{
    private function parse(string $source): \WorkerSafety\Ast\ParseResult
    {
        return (new AstParser())->parse(new SourceFile('/project/src/A.php', 'src/A.php', $source));
    }

    public function test_valid_source_parses_cleanly(): void
    {
        $result = $this->parse("<?php\nclass A {}\n");

        self::assertTrue($result->usable);
        self::assertFalse($result->hasFailures());
        self::assertNotSame([], $result->statements);
    }

    public function test_a_recoverable_syntax_error_still_yields_a_usable_tree(): void
    {
        $result = $this->parse("<?php\nclass A { public static \$x = 1 }\n");

        self::assertTrue($result->hasFailures());
        self::assertTrue($result->usable);
        self::assertSame('src/A.php', $result->failures[0]->relativePath);
        self::assertGreaterThan(0, $result->failures[0]->line);
        self::assertFalse($result->failures[0]->fatal);
    }

    public function test_an_unrecoverable_file_is_reported_as_fatal(): void
    {
        $result = $this->parse("<?php\nclass {{{{ ??? \n");

        self::assertTrue($result->hasFailures());
        self::assertStringContainsString('src/A.php', $result->failures[0]->describe());
    }

    public function test_an_empty_file_is_usable(): void
    {
        $result = $this->parse('');

        self::assertTrue($result->usable);
        self::assertSame([], $result->statements);
    }

    public function test_names_are_resolved_without_replacing_the_source_names(): void
    {
        $result = $this->parse(<<<'PHP'
            <?php

            namespace App\Support;

            use App\Models\User;

            class Ctx
            {
                public static ?User $user = null;
            }
            PHP);

        self::assertTrue($result->usable);

        $namespace = $result->statements[0];
        self::assertInstanceOf(\PhpParser\Node\Stmt\Namespace_::class, $namespace);

        $class = null;

        foreach ($namespace->stmts as $statement) {
            if ($statement instanceof \PhpParser\Node\Stmt\Class_) {
                $class = $statement;
            }
        }

        self::assertNotNull($class);
        self::assertSame('App\\Support\\Ctx', (string) $class->namespacedName);

        $property = $class->stmts[0];
        self::assertInstanceOf(\PhpParser\Node\Stmt\Property::class, $property);

        $type = $property->type;
        self::assertInstanceOf(\PhpParser\Node\NullableType::class, $type);

        $name = $type->type;
        self::assertInstanceOf(\PhpParser\Node\Name::class, $name);

        // The source name is preserved…
        self::assertSame('User', $name->toString());

        // …and the resolved name is available as an attribute.
        $resolved = $name->getAttribute('resolvedName');
        self::assertInstanceOf(\PhpParser\Node\Name::class, $resolved);
        self::assertSame('App\\Models\\User', $resolved->toString());
    }

    public function test_file_positions_are_available_for_columns(): void
    {
        $result = $this->parse("<?php\n    \$a = 1;\n");
        $statement = $result->statements[0] ?? null;

        self::assertInstanceOf(\PhpParser\Node\Stmt::class, $statement);
        self::assertGreaterThan(0, $statement->getStartFilePos());
    }
}
