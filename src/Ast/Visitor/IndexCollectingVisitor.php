<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use WorkerSafety\Ast\AstHelper;
use WorkerSafety\Ast\Index\BindingContext;
use WorkerSafety\Ast\Index\ClassKind;
use WorkerSafety\Ast\Index\ContainerBindingCollector;
use WorkerSafety\Ast\Index\MethodShape;
use WorkerSafety\Ast\Index\ProjectIndex;
use WorkerSafety\Ast\Index\PropertyShape;
use WorkerSafety\Ast\Index\StateWrite;
use WorkerSafety\Ast\Index\StaticLocalVariable;
use WorkerSafety\Ast\Index\WriteKind;
use WorkerSafety\Support\NameHeuristics;
use WorkerSafety\Support\SourceFile;

/**
 * Builds the project-wide semantic model from one file's AST.
 *
 * Everything the naming, mutability and lifetime heuristics need is derived
 * here so that the rules themselves stay small and testable.
 */
final class IndexCollectingVisitor extends NodeVisitorAbstract
{
    /**
     * Functions that make an array grow.
     *
     * @var array<string, true>
     */
    private const GROWING_FUNCTIONS = ['array_push' => true, 'array_unshift' => true];

    /**
     * Functions that make an array shrink; seeing one counts as a release path.
     *
     * @var array<string, true>
     */
    private const SHRINKING_FUNCTIONS = [
        'array_shift' => true,
        'array_pop' => true,
        // array_splice() takes its subject by reference; array_slice() returns
        // a copy and leaves the source untouched, so it is not a release path.
        'array_splice' => true,
    ];

    /**
     * @var list<ClassShapeBuilder>
     */
    private array $classStack = [];

    /**
     * @var list<FunctionScope>
     */
    private array $functionStack = [];

    private int $loopDepth = 0;

    private int $conditionalDepth = 0;

    /**
     * @param list<ContainerBindingCollector> $bindingCollectors
     */
    public function __construct(
        private readonly ProjectIndex $index,
        private readonly SourceFile $file,
        private readonly array $bindingCollectors = [],
    ) {
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->classStack = [];
        $this->functionStack = [];
        $this->loopDepth = 0;
        $this->conditionalDepth = 0;

        return null;
    }

    public function enterNode(Node $node): null
    {
        $this->enterControlFlow($node);

        if ($node instanceof Stmt\ClassLike) {
            $this->enterClassLike($node);

            return null;
        }

        if ($node instanceof Stmt\TraitUse) {
            $this->currentClass()?->addTraits(array_values(array_map(
                static fn (Node\Name $name): string => AstHelper::nameToString($name),
                $node->traits,
            )));

            return null;
        }

        if ($node instanceof Stmt\Property) {
            $this->collectProperty($node);

            return null;
        }

        if ($node instanceof Stmt\ClassMethod) {
            $this->pushFunctionScope($node->name->toString(), true, $node->isStatic());
            $this->collectPromotedProperties($node);

            return null;
        }

        if ($node instanceof Stmt\Function_) {
            $this->pushFunctionScope($node->name->toString(), false, false);

            return null;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->pushFunctionScope(null, false, $node->static);

            return null;
        }

        if ($node instanceof Stmt\Static_) {
            $this->collectStaticLocals($node);

            return null;
        }

        if ($node instanceof Expr\New_) {
            $this->collectInstantiation($node);

            return null;
        }

        $this->collectWrites($node);
        $this->collectBindings($node);

        return null;
    }

    public function leaveNode(Node $node): null
    {
        $this->leaveControlFlow($node);

        if ($node instanceof Stmt\ClassLike) {
            $builder = array_pop($this->classStack);

            if ($builder instanceof ClassShapeBuilder) {
                $this->index->addClass($builder->build());
            }

            return null;
        }

        if ($node instanceof Stmt\ClassMethod) {
            $scope = $this->popFunctionScope();

            if ($scope instanceof FunctionScope) {
                $this->currentClass()?->addMethod($this->buildMethodShape($node, $scope));
            }

            return null;
        }

        if ($node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->popFunctionScope();
        }

        return null;
    }

    /**
     * Nodes that make a statement conditional, repeated, or unreachable.
     */
    private function enterControlFlow(Node $node): void
    {
        if (self::isLoop($node)) {
            ++$this->loopDepth;
        } elseif (self::isBranch($node)) {
            ++$this->conditionalDepth;
        }

    }

    private function leaveControlFlow(Node $node): void
    {
        if (self::isLoop($node)) {
            --$this->loopDepth;
        } elseif (self::isBranch($node)) {
            --$this->conditionalDepth;
        }

        // Set on leave, so the returned expression itself still counts as
        // reached while everything after it does not. `goto` belongs here too:
        // it can jump over the statements that follow.
        if ($node instanceof Stmt\Return_
            || $node instanceof Stmt\Goto_
            || $node instanceof Expr\Throw_
            || $node instanceof Expr\Exit_
        ) {
            $scope = $this->currentFunction();

            if ($scope instanceof FunctionScope) {
                $scope->sawEarlyExit = true;
            }
        }
    }

    private static function isLoop(Node $node): bool
    {
        return $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_;
    }

    private static function isBranch(Node $node): bool
    {
        return $node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\Else_
            || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\Catch_
            || $node instanceof Expr\Match_
            || $node instanceof Expr\Ternary
            // `$evict && array_pop(...)` only evaluates its right operand
            // sometimes, so nothing inside is guaranteed to run.
            || $node instanceof Expr\BinaryOp\BooleanAnd
            || $node instanceof Expr\BinaryOp\BooleanOr
            || $node instanceof Expr\BinaryOp\LogicalAnd
            || $node instanceof Expr\BinaryOp\LogicalOr
            || $node instanceof Expr\BinaryOp\Coalesce
            // `$v ??= self::$x = []` only evaluates the right-hand side when
            // the target is unset — which also makes the `??=` write itself
            // conditional.
            || $node instanceof Expr\AssignOp\Coalesce
            // `$sink?->consume(self::$x = [])`, and everything further along
            // the same chain.
            || self::isShortCircuitedChain($node);
    }

    /**
     * True for a node that a nullsafe operator can skip.
     *
     * `?->` short-circuits the *whole* chain, not just its own link: when
     * `$sink` is null in `$sink?->next()->consume(self::$x = [])`, neither
     * `consume()` nor its arguments are evaluated. So a call or fetch counts as
     * conditional when any link in the receiver chain leading to it is
     * nullsafe.
     */
    private static function isShortCircuitedChain(Node $node): bool
    {
        $current = $node;

        while ($current !== null) {
            if ($current instanceof Expr\NullsafeMethodCall || $current instanceof Expr\NullsafePropertyFetch) {
                return true;
            }

            $current = match (true) {
                $current instanceof Expr\MethodCall,
                $current instanceof Expr\PropertyFetch,
                $current instanceof Expr\ArrayDimFetch => $current->var,
                default => null,
            };
        }

        return false;
    }

    private function enterClassLike(Stmt\ClassLike $node): void
    {
        $shortName = $node->name?->toString();
        $isAnonymous = $shortName === null;

        if ($isAnonymous) {
            $shortName = 'class@anonymous';
            $name = sprintf('class@anonymous:%s:%d', $this->file->relativePath, $node->getStartLine());
        } else {
            $namespaced = $node->namespacedName;
            $name = $namespaced instanceof Node\Name ? $namespaced->toString() : $shortName;
        }

        $parent = null;
        $interfaces = [];

        if ($node instanceof Stmt\Class_) {
            $parent = $node->extends instanceof Node\Name ? AstHelper::nameToString($node->extends) : null;
            $interfaces = self::namesToStrings($node->implements);
        } elseif ($node instanceof Stmt\Interface_) {
            $interfaces = self::namesToStrings($node->extends);
        } elseif ($node instanceof Stmt\Enum_) {
            $interfaces = self::namesToStrings($node->implements);
        }

        $this->classStack[] = new ClassShapeBuilder(
            $name,
            $shortName,
            $this->classKind($node),
            $node instanceof Stmt\Class_ && $node->isFinal(),
            $node instanceof Stmt\Class_ && $node->isAbstract(),
            $parent,
            $interfaces,
            AstHelper::location($node, $this->file),
            $isAnonymous,
            // PHP 8.2 `readonly class`: every declared property is readonly,
            // including promoted constructor parameters.
            $node instanceof Stmt\Class_ && $node->isReadonly(),
        );
    }

    /**
     * @param array<array-key, Node\Name> $names
     *
     * @return list<string>
     */
    private static function namesToStrings(array $names): array
    {
        return array_values(array_map(
            static fn (Node\Name $name): string => AstHelper::nameToString($name),
            $names,
        ));
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

    private function collectProperty(Stmt\Property $node): void
    {
        $class = $this->currentClass();

        if (!$class instanceof ClassShapeBuilder) {
            return;
        }

        $visibility = match (true) {
            $node->isPrivate() => 'private',
            $node->isProtected() => 'protected',
            default => 'public',
        };

        foreach ($node->props as $prop) {
            $location = AstHelper::location($node, $this->file);

            $class->addProperty(new PropertyShape(
                $prop->name->toString(),
                $class->name,
                $node->isStatic(),
                $node->isReadonly() || $class->isReadonly,
                false,
                $visibility,
                AstHelper::typeToString($node->type),
                AstHelper::typeClassNames($node->type),
                AstHelper::typeIsScalarOnly($node->type),
                AstHelper::typeIsArray($node->type),
                AstHelper::defaultValueKind($prop->default),
                $location,
                $this->file->snippet($location->line),
                $this->file->identitySource($node->getStartFilePos(), $node->getEndFilePos()),
            ));
        }
    }

    private function collectPromotedProperties(Stmt\ClassMethod $node): void
    {
        $class = $this->currentClass();

        if (!$class instanceof ClassShapeBuilder || strtolower($node->name->toString()) !== '__construct') {
            return;
        }

        foreach ($node->params as $param) {
            if (!$param->isPromoted() || !$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            $visibility = match (true) {
                $param->isPrivate() => 'private',
                $param->isProtected() => 'protected',
                default => 'public',
            };

            $location = AstHelper::location($param, $this->file);

            $class->addProperty(new PropertyShape(
                $param->var->name,
                $class->name,
                false,
                $param->isReadonly() || $class->isReadonly,
                true,
                $visibility,
                AstHelper::typeToString($param->type),
                AstHelper::typeClassNames($param->type),
                AstHelper::typeIsScalarOnly($param->type),
                AstHelper::typeIsArray($param->type),
                AstHelper::defaultValueKind($param->default),
                $location,
                $this->file->snippet($location->line),
                $this->file->identitySource($param->getStartFilePos(), $param->getEndFilePos()),
            ));
        }
    }

    private function collectStaticLocals(Stmt\Static_ $node): void
    {
        $scope = $this->currentFunction();

        if (!$scope instanceof FunctionScope) {
            return;
        }

        foreach ($node->vars as $staticVar) {
            if (!is_string($staticVar->var->name)) {
                continue;
            }

            $location = AstHelper::location($node, $this->file);

            $scope->declareStaticLocal(
                $staticVar->var->name,
                $location,
                $this->file->snippet($location->line),
                AstHelper::defaultValueKind($staticVar->default),
                $this->file->identitySource($node->getStartFilePos(), $node->getEndFilePos()),
            );
        }
    }

    private function collectInstantiation(Expr\New_ $node): void
    {
        $scope = $this->currentFunction();

        if (!$scope instanceof FunctionScope) {
            return;
        }

        if ($node->class instanceof Node\Name && AstHelper::refersToSelf($node->class, $this->currentClassName())) {
            $scope->markInstantiatesSelf();
        }
    }

    private function collectWrites(Node $node): void
    {
        if ($node instanceof Expr\Assign) {
            $kind = AstHelper::isEmptyArray($node->expr) || AstHelper::isNullConstant($node->expr)
                ? WriteKind::Clear
                : WriteKind::Assign;

            $this->recordWrite($node->var, $kind, $node);

            return;
        }

        if ($node instanceof Expr\AssignRef) {
            $this->recordWrite($node->var, WriteKind::Assign, $node);
            $this->recordWrite($node->expr, WriteKind::Reference, $node);

            return;
        }

        if ($node instanceof Expr\AssignOp) {
            $this->recordWrite($node->var, WriteKind::Compound, $node);

            return;
        }

        if ($node instanceof Expr\PreInc || $node instanceof Expr\PostInc
            || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec
        ) {
            $this->recordWrite($node->var, WriteKind::IncDec, $node);

            return;
        }

        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $var) {
                $this->recordWrite($var, WriteKind::Unset, $node);
            }

            return;
        }

        if ($node instanceof Expr\FuncCall) {
            $this->collectArrayFunctionWrite($node);
        }
    }

    private function collectArrayFunctionWrite(Expr\FuncCall $node): void
    {
        $name = AstHelper::functionName($node);

        if ($name === null) {
            return;
        }

        $kind = match (true) {
            isset(self::GROWING_FUNCTIONS[$name]) => WriteKind::Grow,
            isset(self::SHRINKING_FUNCTIONS[$name]) => WriteKind::Shrink,
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $first = $node->args[0] ?? null;

        if (!$first instanceof Node\Arg) {
            return;
        }

        // The function decides the effect; the dimension only says which
        // collection it lands on.
        $this->recordWrite($first->value, $kind, $node, true);
    }

    /**
     * Attribute a mutation to a static property, an instance property or a
     * static local variable.
     */
    private function recordWrite(Expr $target, WriteKind $kind, Node $node, bool $kindIsEffect = false): void
    {
        $base = AstHelper::unwrapArrayDim($target);
        $dimension = $target instanceof Expr\ArrayDimFetch
            ? $this->dimensionWrite($target, $base)
            : null;

        // A write through a dimension addresses one entry, never the whole
        // collection: `unset(self::$x[$k])` removes a single entry, and
        // `self::$x['last'] = []` assigns an empty array *into* a key, which
        // can even add one. Only a write to the property itself can clear it.
        //
        // A call such as `array_push(self::$x['bucket'], $v)` is different: the
        // function already states the effect, and the dimension only says which
        // collection it applies to. The fixed outer key bounds how many keys
        // `$x` has, not how large the array under `bucket` grows.
        if ($dimension === null || $kindIsEffect) {
            $effective = $kind;
            $literalKey = false;
        } else {
            $effective = $kind === WriteKind::Unset ? WriteKind::Shrink : $dimension['kind'];
            $literalKey = $dimension['literalKey'];
        }

        if ($base instanceof Expr\StaticPropertyFetch) {
            $this->recordStaticPropertyWrite($base, $effective, $node, $literalKey);

            return;
        }

        if ($base instanceof Expr\PropertyFetch) {
            $this->recordInstancePropertyWrite($base);

            return;
        }

        if ($base instanceof Expr\Variable && is_string($base->name)) {
            $scope = $this->currentFunction();

            if ($scope instanceof FunctionScope && $scope->hasStaticLocal($base->name)) {
                $scope->recordStaticLocalWrite($base->name, $this->makeWrite($effective, $node, $literalKey));
            }
        }
    }

    /**
     * Classify a write through one or more array dimensions.
     *
     * `self::$x[] = …` appends; `self::$x[$k] = …` is a keyed write. The key is
     * only "literal" when *every* dimension in the chain is a compile-time
     * constant: `self::$x['bucket'][] = …` writes a fixed outer key but still
     * grows the nested array without bound.
     *
     * @return array{kind: WriteKind, literalKey: bool}|null
     */
    private function dimensionWrite(Expr\ArrayDimFetch $target, Expr $base): ?array
    {
        /** @var list<Expr|null> $dimensions outermost first */
        $dimensions = [];
        $current = $target;

        while (true) {
            $dimensions[] = $current->dim;
            $var = $current->var;

            if ($var === $base) {
                break;
            }

            if (!$var instanceof Expr\ArrayDimFetch) {
                return null;
            }

            $current = $var;
        }

        // The dimension closest to the property decides how the property
        // itself is written.
        $rootDimension = $dimensions[count($dimensions) - 1];

        $literalKey = true;

        foreach ($dimensions as $dimension) {
            if (!self::isFixedKey($dimension)) {
                $literalKey = false;

                break;
            }
        }

        return [
            'kind' => $rootDimension === null ? WriteKind::Append : WriteKind::KeyedWrite,
            'literalKey' => $literalKey,
        ];
    }

    /**
     * A key that is fixed at compile time, so writing through it cannot add an
     * unpredictable number of entries.
     *
     * An interpolated string ("row_$id") is a scalar node but not a fixed key.
     */
    private static function isFixedKey(?Expr $dimension): bool
    {
        if ($dimension === null) {
            return false;
        }

        return $dimension instanceof Node\Scalar\String_
            || $dimension instanceof Node\Scalar\Int_
            || $dimension instanceof Node\Scalar\Float_
            || $dimension instanceof Expr\ClassConstFetch
            || $dimension instanceof Expr\ConstFetch;
    }

    private function recordStaticPropertyWrite(
        Expr\StaticPropertyFetch $fetch,
        WriteKind $kind,
        Node $node,
        bool $literalKey = false,
    ): void {
        if (!$fetch->name instanceof Node\VarLikeIdentifier) {
            return;
        }

        $class = AstHelper::resolveClassReference(
            $fetch->class,
            $this->currentClassName(),
            $this->currentParentName(),
        );

        if ($class === null) {
            return;
        }

        $property = $fetch->name->toString();

        $this->index->addStaticWrite($class, $property, $this->makeWrite($kind, $node, $literalKey));
        $this->currentFunction()?->recordStaticPropertyAssignment($property);
    }

    private function recordInstancePropertyWrite(Expr\PropertyFetch $fetch): void
    {
        if (!$fetch->var instanceof Expr\Variable || $fetch->var->name !== 'this') {
            return;
        }

        if (!$fetch->name instanceof Identifier) {
            return;
        }

        $this->currentFunction()?->recordInstancePropertyAssignment($fetch->name->toString());
    }

    private function makeWrite(WriteKind $kind, Node $node, bool $literalKey = false): StateWrite
    {
        $location = AstHelper::location($node, $this->file);
        $scope = $this->currentFunction();
        $methodScope = $scope instanceof FunctionScope ? ($scope->methodScope ?? $scope) : null;
        $methodName = $methodScope?->name;

        // A write is attributed to the function it is *declared* in, but a
        // closure body is not executed by that function — `$reset = function ()
        // { self::$x = []; };` never runs on its own. Declaration scope is not
        // execution scope, so nothing inside a closure is guaranteed.
        $inClosure = $scope instanceof FunctionScope && $scope->name === null;

        return new StateWrite(
            $kind,
            $location,
            $this->file->snippet($location->line),
            $this->currentClassName(),
            $methodName,
            $methodName !== null && strtolower($methodName) === '__construct',
            $methodName !== null && NameHeuristics::looksLikeReset($methodName),
            $literalKey,
            !$inClosure
                && $this->loopDepth === 0
                && $this->conditionalDepth === 0
                && !($scope instanceof FunctionScope && $scope->sawEarlyExit),
        );
    }

    private function collectBindings(Node $node): void
    {
        if ($this->bindingCollectors === []) {
            return;
        }

        if (!$node instanceof Expr\MethodCall && !$node instanceof Expr\StaticCall) {
            return;
        }

        $scope = $this->currentFunction();
        $methodScope = $scope instanceof FunctionScope ? ($scope->methodScope ?? $scope) : null;

        $context = new BindingContext(
            $this->file,
            $this->currentClassName(),
            $this->currentParentName(),
            $methodScope?->name,
        );

        foreach ($this->bindingCollectors as $collector) {
            foreach ($collector->collect($node, $context) as $binding) {
                $this->index->addBinding($binding);
            }
        }
    }

    private function buildMethodShape(Stmt\ClassMethod $node, FunctionScope $scope): MethodShape
    {
        $class = $this->currentClass();
        $className = $class instanceof ClassShapeBuilder ? $class->name : '';
        $returnType = AstHelper::typeToString($node->returnType);

        $visibility = match (true) {
            $node->isPrivate() => 'private',
            $node->isProtected() => 'protected',
            default => 'public',
        };

        return new MethodShape(
            $node->name->toString(),
            $className,
            $node->isStatic(),
            $visibility,
            $returnType,
            $this->returnsSelf($node, $className),
            $scope->instantiatesSelf,
            NameHeuristics::looksLikeReset($node->name->toString()),
            $scope->assignedStaticProperties,
            $scope->assignedInstanceProperties,
            AstHelper::location($node, $this->file),
        );
    }

    private function returnsSelf(Stmt\ClassMethod $node, string $className): bool
    {
        $type = $node->returnType;

        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        if ($type instanceof Identifier) {
            return in_array(strtolower($type->toString()), ['self', 'static'], true);
        }

        if ($type instanceof Node\Name) {
            $resolved = AstHelper::nameToString($type);

            return $className !== '' && strtolower($resolved) === strtolower($className);
        }

        return false;
    }

    private function pushFunctionScope(?string $name, bool $isMethod, bool $isStatic): void
    {
        $current = $this->currentFunction();
        $methodScope = null;

        if (!$isMethod && $current instanceof FunctionScope) {
            // Closures attribute their writes to the method that declares them.
            $methodScope = $current->methodScope ?? $current;
        }

        $this->functionStack[] = new FunctionScope(
            $name,
            $isMethod,
            $isStatic,
            $this->currentClass(),
            $methodScope,
        );
    }

    private function popFunctionScope(): ?FunctionScope
    {
        $scope = array_pop($this->functionStack);

        if (!$scope instanceof FunctionScope) {
            return null;
        }

        foreach ($scope->staticLocals as $name => $local) {
            $this->index->addStaticLocal(new StaticLocalVariable(
                $name,
                $this->currentClassName(),
                $scope->name,
                $local['default'],
                $local['location'],
                $local['snippet'],
                $local['writes'],
                $local['excerpt'],
            ));
        }

        return $scope;
    }

    private function currentClass(): ?ClassShapeBuilder
    {
        return $this->classStack === [] ? null : $this->classStack[count($this->classStack) - 1];
    }

    private function currentClassName(): ?string
    {
        return $this->currentClass()?->name;
    }

    private function currentParentName(): ?string
    {
        return $this->currentClass()?->parent;
    }

    private function currentFunction(): ?FunctionScope
    {
        return $this->functionStack === [] ? null : $this->functionStack[count($this->functionStack) - 1];
    }
}
