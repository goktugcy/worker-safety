<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use WorkerSafety\Ast\AstHelper;

/**
 * Finds the first statically resolvable `new X(...)` in a subtree.
 *
 * Used to learn the concrete type from a container binding closure:
 * `$this->app->singleton(Ctx::class, fn () => new Ctx($dep))`.
 */
final class FirstInstantiationFinder extends NodeVisitorAbstract
{
    private ?string $className = null;

    public function __construct(
        private readonly ?string $currentClass,
        private readonly ?string $parentClass,
    ) {
    }

    public static function find(Node $subject, ?string $currentClass, ?string $parentClass): ?string
    {
        $finder = new self($currentClass, $parentClass);
        (new NodeTraverser($finder))->traverse([$subject]);

        return $finder->className;
    }

    public function enterNode(Node $node): null
    {
        if ($this->className !== null || !$node instanceof Expr\New_) {
            return null;
        }

        if (!$node->class instanceof Node\Name) {
            return null;
        }

        $this->className = AstHelper::resolveClassReference($node->class, $this->currentClass, $this->parentClass);

        return null;
    }
}
