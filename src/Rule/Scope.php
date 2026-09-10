<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use WorkerSafety\Ast\Index\ClassKind;
use WorkerSafety\Finding\SymbolContext;

/**
 * The class / function nesting at the node currently being visited.
 *
 * Maintained by {@see RuleDispatchVisitor} and exposed to rules read-only
 * through {@see RuleContext}.
 */
final class Scope
{
    /**
     * @var list<array{name: string, short: string, parent: string|null, kind: ClassKind, node: Stmt\ClassLike}>
     */
    private array $classes = [];

    /**
     * @var list<array{name: string|null, node: FunctionLike, isMethod: bool, isStatic: bool}>
     */
    private array $functions = [];

    public function reset(): void
    {
        $this->classes = [];
        $this->functions = [];
    }

    public function enterClass(
        Stmt\ClassLike $node,
        string $name,
        string $shortName,
        ?string $parent,
        ClassKind $kind,
    ): void {
        $this->classes[] = [
            'name' => $name,
            'short' => $shortName,
            'parent' => $parent,
            'kind' => $kind,
            'node' => $node,
        ];
    }

    public function leaveClass(): void
    {
        array_pop($this->classes);
    }

    public function enterFunction(FunctionLike $node, ?string $name, bool $isMethod, bool $isStatic): void
    {
        $this->functions[] = [
            'name' => $name,
            'node' => $node,
            'isMethod' => $isMethod,
            'isStatic' => $isStatic,
        ];
    }

    public function leaveFunction(): void
    {
        array_pop($this->functions);
    }

    public function isInsideClass(): bool
    {
        return $this->classes !== [];
    }

    public function isInsideFunction(): bool
    {
        return $this->functions !== [];
    }

    public function className(): ?string
    {
        $current = $this->currentClass();

        return $current === null ? null : $current['name'];
    }

    public function classShortName(): ?string
    {
        $current = $this->currentClass();

        return $current === null ? null : $current['short'];
    }

    public function parentClass(): ?string
    {
        $current = $this->currentClass();

        return $current === null ? null : $current['parent'];
    }

    public function classKind(): ?ClassKind
    {
        $current = $this->currentClass();

        return $current === null ? null : $current['kind'];
    }

    public function classNode(): ?Stmt\ClassLike
    {
        $current = $this->currentClass();

        return $current === null ? null : $current['node'];
    }

    /**
     * Innermost function-like node, closures included.
     */
    public function functionNode(): ?FunctionLike
    {
        $current = $this->currentFunction();

        return $current === null ? null : $current['node'];
    }

    /**
     * Name of the nearest enclosing named function or method.
     */
    public function functionName(): ?string
    {
        for ($i = count($this->functions) - 1; $i >= 0; --$i) {
            if ($this->functions[$i]['name'] !== null) {
                return $this->functions[$i]['name'];
            }
        }

        return null;
    }

    /**
     * Name of the nearest enclosing method (null inside plain functions).
     */
    public function methodName(): ?string
    {
        for ($i = count($this->functions) - 1; $i >= 0; --$i) {
            if ($this->functions[$i]['isMethod']) {
                return $this->functions[$i]['name'];
            }
        }

        return null;
    }

    /**
     * Nearest enclosing method node, used by rules that need to inspect the
     * whole method body from one of its statements.
     */
    public function methodNode(): ?Stmt\ClassMethod
    {
        for ($i = count($this->functions) - 1; $i >= 0; --$i) {
            $node = $this->functions[$i]['node'];

            if ($node instanceof Stmt\ClassMethod) {
                return $node;
            }
        }

        return null;
    }

    public function isInsideClosure(): bool
    {
        $current = $this->currentFunction();

        return $current !== null && $current['name'] === null;
    }

    public function symbol(?string $property = null, ?string $variable = null): SymbolContext
    {
        return new SymbolContext(
            $this->className(),
            $property === null ? $this->methodName() : null,
            $property,
            $variable,
        );
    }

    /**
     * @return array{name: string, short: string, parent: string|null, kind: ClassKind, node: Stmt\ClassLike}|null
     */
    private function currentClass(): ?array
    {
        return $this->classes === [] ? null : $this->classes[count($this->classes) - 1];
    }

    /**
     * @return array{name: string|null, node: FunctionLike, isMethod: bool, isStatic: bool}|null
     */
    private function currentFunction(): ?array
    {
        return $this->functions === [] ? null : $this->functions[count($this->functions) - 1];
    }

    /**
     * Convenience for rules that need to know whether a node is a top-level
     * statement rather than part of a function body.
     */
    public function isFileScope(): bool
    {
        return $this->classes === [] && $this->functions === [];
    }

    public function describeLocationScope(): string
    {
        $class = $this->className();
        $function = $this->functionName();

        if ($class !== null && $function !== null) {
            return $class . '::' . $function . '()';
        }

        if ($class !== null) {
            return $class;
        }

        if ($function !== null) {
            return $function . '()';
        }

        return 'file scope';
    }

    /**
     * Node type guard used by the dispatch visitor.
     */
    public static function isFunctionLike(Node $node): bool
    {
        return $node instanceof FunctionLike;
    }
}
