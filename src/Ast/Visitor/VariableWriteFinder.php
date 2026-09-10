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
 * Answers "is this local variable written anywhere in this subtree?".
 *
 * Used for the small, bounded lookups a node-driven rule needs, such as
 * deciding whether a `global $user;` declaration is followed by an assignment.
 */
final class VariableWriteFinder extends NodeVisitorAbstract
{
    private bool $found = false;

    /**
     * @param list<string> $names variable names without the leading `$`
     */
    public function __construct(private readonly array $names)
    {
    }

    /**
     * @param list<string> $names
     * @param Node|list<Node> $subject
     */
    public static function writesAny(Node|array $subject, array $names): bool
    {
        if ($names === []) {
            return false;
        }

        $finder = new self($names);
        (new NodeTraverser($finder))->traverse(is_array($subject) ? $subject : [$subject]);

        return $finder->found;
    }

    public function enterNode(Node $node): null
    {
        if ($this->found) {
            return null;
        }

        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\AssignRef) {
            $this->check($node->var);

            return null;
        }

        if ($node instanceof Expr\PreInc || $node instanceof Expr\PostInc
            || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec
        ) {
            $this->check($node->var);

            return null;
        }

        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $var) {
                $this->check($var);
            }

            return null;
        }

        if ($node instanceof Stmt\Foreach_) {
            $this->check($node->valueVar);

            if ($node->keyVar instanceof Expr) {
                $this->check($node->keyVar);
            }
        }

        return null;
    }

    private function check(Expr $target): void
    {
        $base = AstHelper::unwrapArrayDim($target);

        if ($base instanceof Expr\Variable && is_string($base->name) && in_array($base->name, $this->names, true)) {
            $this->found = true;
        }
    }
}
