<?php

declare(strict_types=1);

namespace WorkerSafety\Framework\Laravel;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use WorkerSafety\Ast\AstHelper;
use WorkerSafety\Ast\Index\BindingContext;
use WorkerSafety\Ast\Index\ContainerBinding;
use WorkerSafety\Ast\Index\ContainerBindingCollector;
use WorkerSafety\Ast\Visitor\FirstInstantiationFinder;

/**
 * Recognises Illuminate container registrations.
 *
 * Both the receiver and the method name have to match, so a `->bind()` on some
 * unrelated object is not mistaken for a container binding.
 */
final class LaravelContainerBindingCollector implements ContainerBindingCollector
{
    /**
     * Method name => [shared, scoped, sharedFromThirdArgument].
     *
     * @var array<string, array{0: bool, 1: bool, 2: bool}>
     */
    private const BINDING_METHODS = [
        'singleton' => [true, false, false],
        'singletonif' => [true, false, false],
        'scoped' => [true, true, false],
        'scopedif' => [true, true, false],
        'instance' => [true, false, false],
        'bind' => [false, false, true],
        'bindif' => [false, false, true],
    ];

    /**
     * Facades and container classes that expose the binding methods statically.
     *
     * @var list<string>
     */
    private const CONTAINER_CLASSES = ['app', 'container', 'facade'];

    /**
     * Variable and property names that hold a container.
     *
     * @var list<string>
     */
    private const CONTAINER_NAMES = ['app', 'container'];

    public function collect(Node $node, BindingContext $context): iterable
    {
        if ($node instanceof Expr\MethodCall) {
            $binding = $this->fromMethodCall($node, $context);
        } elseif ($node instanceof Expr\StaticCall) {
            $binding = $this->fromStaticCall($node, $context);
        } else {
            return;
        }

        if ($binding instanceof ContainerBinding) {
            yield $binding;
        }
    }

    private function fromMethodCall(Expr\MethodCall $node, BindingContext $context): ?ContainerBinding
    {
        if (!$node->name instanceof Identifier || !$this->isContainerExpression($node->var)) {
            return null;
        }

        return $this->build($node->name->toString(), array_values($node->args), $node, $context);
    }

    private function fromStaticCall(Expr\StaticCall $node, BindingContext $context): ?ContainerBinding
    {
        if (!$node->name instanceof Identifier || !$node->class instanceof Node\Name) {
            return null;
        }

        $className = AstHelper::nameToString($node->class);
        $shortName = strtolower(str_contains($className, '\\')
            ? substr($className, (int) strrpos($className, '\\') + 1)
            : $className);

        if (!in_array($shortName, self::CONTAINER_CLASSES, true)) {
            return null;
        }

        return $this->build($node->name->toString(), array_values($node->args), $node, $context);
    }

    /**
     * @param list<Arg|Node\VariadicPlaceholder> $args
     */
    private function build(string $method, array $args, Node $node, BindingContext $context): ?ContainerBinding
    {
        $descriptor = self::BINDING_METHODS[strtolower($method)] ?? null;

        if ($descriptor === null) {
            return null;
        }

        [$shared, $scoped, $sharedFromArgument] = $descriptor;

        $abstractArg = $this->argument($args, 0, 'abstract');

        if ($abstractArg === null) {
            return null;
        }

        $key = AstHelper::containerKeyFromExpr($abstractArg, $context->currentClass, $context->parentClass);

        if ($key === null) {
            return null;
        }

        [$abstract, $abstractIsClass] = $key;

        $concreteArg = $this->argument($args, 1, 'concrete');
        $concrete = $concreteArg === null ? null : $this->resolveConcrete($concreteArg, $context);

        if ($sharedFromArgument) {
            $sharedArg = $this->argument($args, 2, 'shared');
            $shared = $sharedArg instanceof Expr\ConstFetch
                && strtolower($sharedArg->name->toString()) === 'true';
        }

        $location = AstHelper::location($node, $context->file);

        return new ContainerBinding(
            strtolower($method),
            $abstract,
            $concrete,
            $shared,
            $scoped,
            $location,
            $context->file->snippet($location->line),
            $context->currentClass,
            $context->currentMethod,
            $abstractIsClass,
            $context->file->identitySource($node->getStartFilePos(), $node->getEndFilePos()),
        );
    }

    private function resolveConcrete(Expr $expr, BindingContext $context): ?string
    {
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return FirstInstantiationFinder::find($expr, $context->currentClass, $context->parentClass);
        }

        // `instance('ctx', new Context())` hands the container a built object.
        if ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name) {
            return AstHelper::resolveClassReference($expr->class, $context->currentClass, $context->parentClass);
        }

        return AstHelper::classNameFromExpr($expr, $context->currentClass, $context->parentClass);
    }

    /**
     * Resolve an argument positionally or by name.
     *
     * @param list<Arg|Node\VariadicPlaceholder> $args
     */
    private function argument(array $args, int $position, string $name): ?Expr
    {
        foreach ($args as $arg) {
            if ($arg instanceof Arg && $arg->name instanceof Identifier && $arg->name->toString() === $name) {
                return $arg->value;
            }
        }

        $positional = [];

        foreach ($args as $arg) {
            if ($arg instanceof Arg && $arg->name === null) {
                $positional[] = $arg->value;
            }
        }

        return $positional[$position] ?? null;
    }

    private function isContainerExpression(Expr $expr): bool
    {
        if ($expr instanceof Expr\PropertyFetch && $expr->name instanceof Identifier) {
            return in_array(strtolower($expr->name->toString()), self::CONTAINER_NAMES, true);
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            return in_array(strtolower($expr->name), self::CONTAINER_NAMES, true);
        }

        // `app()->singleton(...)`
        if ($expr instanceof Expr\FuncCall) {
            return AstHelper::functionName($expr) === 'app' && $expr->args === [];
        }

        // `Container::getInstance()->singleton(...)`
        if ($expr instanceof Expr\StaticCall
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Identifier
        ) {
            $shortName = strtolower(str_replace('\\', '/', AstHelper::nameToString($expr->class)));
            $shortName = substr($shortName, (int) strrpos($shortName, '/') + 1);

            return $shortName === 'container' && strtolower($expr->name->toString()) === 'getinstance';
        }

        return false;
    }
}
