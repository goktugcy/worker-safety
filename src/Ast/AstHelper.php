<?php

declare(strict_types=1);

namespace WorkerSafety\Ast;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar;
use PhpParser\Node\UnionType;
use WorkerSafety\Ast\Index\DefaultValueKind;
use WorkerSafety\Finding\Location;
use WorkerSafety\Support\SourceFile;

/**
 * Small, dependency free helpers on top of the php-parser node tree.
 */
final class AstHelper
{
    /**
     * @var list<string>
     */
    private const SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'true', 'false', 'null', 'void', 'never'];

    private function __construct()
    {
    }

    /**
     * Fully qualified name for a declaration or a referenced name node.
     */
    public static function nameToString(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        if ($resolved instanceof Name) {
            return $resolved->toString();
        }

        $namespaced = $name->getAttribute('namespacedName');

        if ($namespaced instanceof Name) {
            return $namespaced->toString();
        }

        return $name->toString();
    }

    /**
     * Resolve the class part of a static fetch/call.
     *
     * Returns null for dynamic references such as `$class::$prop`.
     */
    public static function resolveClassReference(
        Node $classNode,
        ?string $currentClass,
        ?string $parentClass,
    ): ?string {
        if (!$classNode instanceof Name) {
            return null;
        }

        $lower = strtolower($classNode->toString());

        if ($lower === 'self' || $lower === 'static') {
            return $currentClass;
        }

        if ($lower === 'parent') {
            return $parentClass;
        }

        return self::nameToString($classNode);
    }

    /**
     * True when the class reference is `self`, `static` or the declaring class itself.
     */
    public static function refersToSelf(Node $classNode, ?string $currentClass): bool
    {
        if (!$classNode instanceof Name) {
            return false;
        }

        $lower = strtolower($classNode->toString());

        if ($lower === 'self' || $lower === 'static') {
            return true;
        }

        return $currentClass !== null && strtolower(self::nameToString($classNode)) === strtolower($currentClass);
    }

    public static function location(Node $node, SourceFile $file): Location
    {
        $line = $node->getStartLine();
        $line = $line > 0 ? $line : 1;

        return new Location(
            $file->absolutePath,
            $file->relativePath,
            $line,
            self::column($file, $node->getStartFilePos()),
            $node->getEndLine() > 0 ? $node->getEndLine() : null,
        );
    }

    /**
     * 1-indexed column for a byte offset in the file.
     */
    public static function column(SourceFile $file, int $filePos): ?int
    {
        if ($filePos < 0) {
            return null;
        }

        $lineStart = strrpos(substr($file->source, 0, $filePos), "\n");

        if ($lineStart === false) {
            return $filePos + 1;
        }

        return $filePos - $lineStart;
    }

    /**
     * Render a type declaration back to source-ish text.
     */
    public static function typeToString(null|Identifier|Name|ComplexType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Identifier) {
            return $type->toString();
        }

        if ($type instanceof Name) {
            return self::nameToString($type);
        }

        if ($type instanceof NullableType) {
            return '?' . (self::typeToString($type->type) ?? 'mixed');
        }

        if ($type instanceof UnionType) {
            return implode('|', array_map(
                static fn (Node $part): string => self::typeToString(self::asTypeNode($part)) ?? 'mixed',
                $type->types,
            ));
        }

        if ($type instanceof IntersectionType) {
            return implode('&', array_map(
                static fn (Node $part): string => self::typeToString(self::asTypeNode($part)) ?? 'mixed',
                $type->types,
            ));
        }

        return null;
    }

    /**
     * Every class-like name mentioned by a type declaration.
     *
     * @return list<string>
     */
    public static function typeClassNames(null|Identifier|Name|ComplexType $type): array
    {
        if ($type === null || $type instanceof Identifier) {
            return [];
        }

        if ($type instanceof Name) {
            return [self::nameToString($type)];
        }

        if ($type instanceof NullableType) {
            return self::typeClassNames($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            $names = [];

            foreach ($type->types as $part) {
                foreach (self::typeClassNames(self::asTypeNode($part)) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return [];
    }

    /**
     * True when the declared type can only ever hold scalars (or null).
     */
    public static function typeIsScalarOnly(null|Identifier|Name|ComplexType $type): bool
    {
        if ($type === null) {
            return false;
        }

        if ($type instanceof Identifier) {
            return in_array(strtolower($type->toString()), self::SCALAR_TYPES, true);
        }

        if ($type instanceof NullableType) {
            return self::typeIsScalarOnly($type->type);
        }

        if ($type instanceof UnionType) {
            foreach ($type->types as $part) {
                if (!self::typeIsScalarOnly(self::asTypeNode($part))) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    public static function typeIsArray(null|Identifier|Name|ComplexType $type): bool
    {
        if ($type instanceof Identifier) {
            return strtolower($type->toString()) === 'array';
        }

        if ($type instanceof NullableType) {
            return self::typeIsArray($type->type);
        }

        if ($type instanceof UnionType) {
            foreach ($type->types as $part) {
                if (self::typeIsArray(self::asTypeNode($part))) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function defaultValueKind(?Expr $default): DefaultValueKind
    {
        if ($default === null) {
            return DefaultValueKind::None;
        }

        if (self::isNullConstant($default)) {
            return DefaultValueKind::Null;
        }

        if ($default instanceof Expr\Array_) {
            return $default->items === [] ? DefaultValueKind::EmptyArray : DefaultValueKind::NonEmptyArray;
        }

        if ($default instanceof Scalar) {
            return DefaultValueKind::Scalar;
        }

        if ($default instanceof Expr\New_) {
            return DefaultValueKind::NewObject;
        }

        if ($default instanceof Expr\ConstFetch || $default instanceof Expr\ClassConstFetch) {
            return DefaultValueKind::ConstantExpression;
        }

        if ($default instanceof Expr\UnaryMinus || $default instanceof Expr\UnaryPlus) {
            return self::defaultValueKind($default->expr) === DefaultValueKind::Scalar
                ? DefaultValueKind::Scalar
                : DefaultValueKind::Other;
        }

        if ($default instanceof Expr\BinaryOp) {
            $left = self::defaultValueKind($default->left);
            $right = self::defaultValueKind($default->right);

            return $left->isConstantScalar() && $right->isConstantScalar()
                ? DefaultValueKind::ConstantExpression
                : DefaultValueKind::Other;
        }

        return DefaultValueKind::Other;
    }

    public static function isNullConstant(Expr $expr): bool
    {
        return $expr instanceof Expr\ConstFetch && strtolower($expr->name->toString()) === 'null';
    }

    public static function isEmptyArray(Expr $expr): bool
    {
        return $expr instanceof Expr\Array_ && $expr->items === [];
    }

    /**
     * Peel `$x['a']['b']` down to `$x`.
     */
    public static function unwrapArrayDim(Expr $expr): Expr
    {
        while ($expr instanceof Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }

        return $expr;
    }

    /**
     * Static text of a string literal, or null when it is not a plain literal.
     */
    public static function stringValue(Expr $expr): ?string
    {
        return $expr instanceof Scalar\String_ ? $expr->value : null;
    }

    /**
     * Resolve `Foo::class`, `self::class` or a plain string literal to a class name.
     */
    public static function classNameFromExpr(Expr $expr, ?string $currentClass, ?string $parentClass): ?string
    {
        if ($expr instanceof Expr\ClassConstFetch
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'class'
        ) {
            return self::resolveClassReference($expr->class, $currentClass, $parentClass);
        }

        $literal = self::stringValue($expr);

        if ($literal !== null && $literal !== '' && preg_match('/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $literal) === 1) {
            return ltrim($literal, '\\');
        }

        return null;
    }

    /**
     * A service-container key: either a class reference (`X::class`, or a
     * string spelled like a class) or a free-form string id such as
     * `'auth.context'`.
     *
     * @return array{0: string, 1: bool}|null the key and whether it names a class
     */
    public static function containerKeyFromExpr(Expr $expr, ?string $currentClass, ?string $parentClass): ?array
    {
        $className = self::classNameFromExpr($expr, $currentClass, $parentClass);

        if ($className !== null) {
            return [$className, true];
        }

        $literal = self::stringValue($expr);

        if ($literal !== null && trim($literal) !== '') {
            return [$literal, false];
        }

        return null;
    }

    public static function identifierName(Node $node): ?string
    {
        if ($node instanceof Identifier) {
            return $node->toString();
        }

        if ($node instanceof Name) {
            return $node->toString();
        }

        return null;
    }

    /**
     * Lowercased, resolved name of a plain function call, or null for dynamic calls.
     *
     * Uses the name the resolver worked out rather than the source spelling, so
     * `use function putenv as changeEnv;` is still recognised as `putenv`.
     */
    public static function functionName(Expr\FuncCall $call): ?string
    {
        if (!$call->name instanceof Name) {
            return null;
        }

        $resolved = $call->name->getAttribute('resolvedName');

        if ($resolved instanceof Name) {
            return strtolower(ltrim($resolved->toString(), '\\'));
        }

        return strtolower(ltrim($call->name->toString(), '\\'));
    }

    private static function asTypeNode(Node $node): null|Identifier|Name|ComplexType
    {
        if ($node instanceof Identifier || $node instanceof Name || $node instanceof ComplexType) {
            return $node;
        }

        return null;
    }
}
