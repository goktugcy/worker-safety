<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use WorkerSafety\Ast\AstHelper;
use WorkerSafety\Ast\Index\ClassKind;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Rule\Rule;
use WorkerSafety\Rule\RuleContext;
use WorkerSafety\Rule\Scope;

/**
 * Feeds nodes to the rules that asked for them and keeps the class/function
 * scope in sync.
 *
 * Rules declare node types by class name; because a rule may legitimately ask
 * for an abstract type (`Expr\AssignOp`) or an interface, the mapping from a
 * concrete node class to interested rules is resolved once per node class and
 * then cached for the rest of the scan.
 */
final class RuleDispatchVisitor extends NodeVisitorAbstract
{
    /**
     * @var list<Rule>
     */
    private array $rules;

    /**
     * @var array<class-string<Node>, list<Rule>>
     */
    private array $dispatchCache = [];

    /**
     * @var list<Finding>
     */
    private array $findings = [];

    private RuleContext $context;

    /**
     * @param list<Rule> $rules
     */
    public function __construct(array $rules, private readonly Scope $scope)
    {
        // Rules that request no node types are driven purely by the project
        // index, so they never take part in dispatch.
        $this->rules = array_values(array_filter(
            $rules,
            static fn (Rule $rule): bool => $rule->nodeTypes() !== [],
        ));
    }

    public function startFile(RuleContext $context): void
    {
        $this->context = $context;
        $this->findings = [];
        $this->scope->reset();
    }

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function enterNode(Node $node): null
    {
        $this->pushScope($node);

        foreach ($this->rulesFor($node) as $rule) {
            foreach ($rule->enterNode($node, $this->context) as $finding) {
                $this->findings[] = $finding;
            }
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->scope->leaveClass();

            return null;
        }

        if ($node instanceof Node\FunctionLike) {
            $this->scope->leaveFunction();
        }

        return null;
    }

    private function pushScope(Node $node): void
    {
        if ($node instanceof Stmt\ClassLike) {
            $shortName = $node->name?->toString();

            if ($shortName === null) {
                $shortName = 'class@anonymous';
                $name = sprintf(
                    'class@anonymous:%s:%d',
                    $this->context->file()->relativePath,
                    $node->getStartLine(),
                );
            } else {
                $namespaced = $node->namespacedName;
                $name = $namespaced instanceof Node\Name ? $namespaced->toString() : $shortName;
            }

            $parent = $node instanceof Stmt\Class_ && $node->extends instanceof Node\Name
                ? AstHelper::nameToString($node->extends)
                : null;

            $this->scope->enterClass($node, $name, $shortName, $parent, $this->classKind($node));

            return;
        }

        if ($node instanceof Stmt\ClassMethod) {
            $this->scope->enterFunction($node, $node->name->toString(), true, $node->isStatic());

            return;
        }

        if ($node instanceof Stmt\Function_) {
            $this->scope->enterFunction($node, $node->name->toString(), false, false);

            return;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->scope->enterFunction($node, null, false, $node->static);
        }
    }

    private function classKind(Stmt\ClassLike $node): ClassKind
    {
        return match (true) {
            $node instanceof Stmt\Interface_ => ClassKind::Interface,
            $node instanceof Stmt\Trait_ => ClassKind::Trait,
            $node instanceof Stmt\Enum_ => ClassKind::Enum,
            default => ClassKind::ClassType,
        };
    }

    /**
     * @return list<Rule>
     */
    private function rulesFor(Node $node): array
    {
        $nodeClass = $node::class;

        if (isset($this->dispatchCache[$nodeClass])) {
            return $this->dispatchCache[$nodeClass];
        }

        $interested = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->nodeTypes() as $type) {
                if ($nodeClass === $type || is_a($nodeClass, $type, true)) {
                    $interested[] = $rule;

                    break;
                }
            }
        }

        return $this->dispatchCache[$nodeClass] = $interested;
    }
}
