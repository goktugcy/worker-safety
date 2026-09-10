<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use WorkerSafety\Ast\AstHelper;

/**
 * Works out which class a container factory actually produces.
 *
 * Only the *returned* expression counts. A factory that builds a dependency
 * before returning the service — `$dep = new SafeDep(); return new Ctx();` —
 * must resolve to `Ctx`, and anything less obvious resolves to nothing at all:
 * a wrong answer here attributes one class's mutability to another.
 */
final class FirstInstantiationFinder extends NodeVisitorAbstract
{
    private ?string $className = null;

    private bool $ambiguous = false;

    private int $depth = 0;

    public function __construct(
        private readonly ?string $currentClass,
        private readonly ?string $parentClass,
    ) {
    }

    /**
     * The class a closure or arrow function returns, or null when it cannot be
     * determined with confidence.
     */
    public static function find(Node $subject, ?string $currentClass, ?string $parentClass): ?string
    {
        if ($subject instanceof Expr\ArrowFunction) {
            // `fn () => new Ctx()` — the body is the returned expression.
            return self::classOf($subject->expr, $currentClass, $parentClass);
        }

        if (!$subject instanceof Expr\Closure && !$subject instanceof Stmt\Function_) {
            return self::classOf($subject instanceof Expr ? $subject : null, $currentClass, $parentClass);
        }

        $finder = new self($currentClass, $parentClass);
        (new NodeTraverser($finder))->traverse($subject->stmts);

        return $finder->ambiguous ? null : $finder->className;
    }

    public function enterNode(Node $node): ?int
    {
        // Returns inside a nested function belong to that function, not to ours.
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\Function_) {
            ++$this->depth;

            return null;
        }

        if ($this->depth > 0 || !$node instanceof Stmt\Return_ || $node->expr === null) {
            return null;
        }

        $class = self::classOf($node->expr, $this->currentClass, $this->parentClass);

        if ($class === null) {
            // A return we cannot read means we cannot claim to know the type.
            $this->ambiguous = true;

            return null;
        }

        if ($this->className !== null && strtolower($this->className) !== strtolower($class)) {
            $this->ambiguous = true;

            return null;
        }

        $this->className = $class;

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\Function_) {
            --$this->depth;
        }

        return null;
    }

    private static function classOf(?Expr $expr, ?string $currentClass, ?string $parentClass): ?string
    {
        if (!$expr instanceof Expr\New_ || !$expr->class instanceof Node\Name) {
            return null;
        }

        return AstHelper::resolveClassReference($expr->class, $currentClass, $parentClass);
    }
}
