<?php

declare(strict_types=1);

namespace WorkerSafety\Ast;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use WorkerSafety\Support\SourceFile;

/**
 * Parses PHP source into a name-resolved AST.
 *
 * Nothing in this package ever includes, evaluates or autoloads analyzed code:
 * files are only ever turned into an abstract syntax tree.
 */
final class AstParser
{
    private readonly Parser $parser;

    private readonly NodeTraverser $nameResolvingTraverser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();

        // replaceNodes: false keeps the source-level names intact (useful for
        // reporting) while adding a `resolvedName` attribute we can rely on.
        $this->nameResolvingTraverser = new NodeTraverser(
            new NameResolver(null, ['replaceNodes' => false]),
        );
    }

    public function parse(SourceFile $file): ParseResult
    {
        $errorHandler = new Collecting();

        try {
            $statements = $this->parser->parse($file->source, $errorHandler);
        } catch (\Throwable $exception) {
            // Defensive: php-parser normally routes everything through the
            // error handler, but a broken file must never kill the scan.
            return new ParseResult([], [
                new ParseFailure($file->relativePath, $file->absolutePath, 1, $exception->getMessage(), true),
            ], false);
        }

        $failures = [];

        foreach ($errorHandler->getErrors() as $error) {
            $line = $error->getStartLine();

            $failures[] = new ParseFailure(
                $file->relativePath,
                $file->absolutePath,
                $line > 0 ? $line : 1,
                $error->getRawMessage(),
                $statements === null,
            );
        }

        if ($statements === null) {
            if ($failures === []) {
                $failures[] = new ParseFailure($file->relativePath, $file->absolutePath, 1, 'Unknown parse error', true);
            }

            return new ParseResult([], $failures, false);
        }

        $this->nameResolvingTraverser->traverse($statements);

        return new ParseResult(array_values($statements), $failures, true);
    }
}
