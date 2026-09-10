<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use WorkerSafety\Finding\Location;
use WorkerSafety\Support\NameHeuristics;

/**
 * A declared property, static or instance.
 */
final class PropertyShape
{
    /**
     * @param list<string> $typeClassNames
     */
    public function __construct(
        public readonly string $name,
        public readonly string $declaringClass,
        public readonly bool $isStatic,
        public readonly bool $isReadonly,
        public readonly bool $isPromoted,
        public readonly string $visibility,
        public readonly ?string $typeString,
        public readonly array $typeClassNames,
        public readonly bool $typeIsScalarOnly,
        public readonly bool $typeIsArray,
        public readonly DefaultValueKind $default,
        public readonly Location $location,
        public readonly ?string $snippet,
    ) {
    }

    /**
     * Key used to look writes up in the project index.
     */
    public function writeKey(): string
    {
        return self::makeWriteKey($this->declaringClass, $this->name);
    }

    public static function makeWriteKey(string $class, string $property): string
    {
        return strtolower(ltrim($class, '\\')) . '::$' . $property;
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    /**
     * True when nothing about the declaration itself suggests mutable runtime
     * state: a scalar typed property holding a literal default.
     */
    public function looksLikeConfiguration(): bool
    {
        return $this->typeIsScalarOnly && $this->default->isConstantScalar();
    }

    /**
     * Request vocabulary found in the property name or in its declared type.
     *
     * @return list<string>
     */
    public function requestTokens(): array
    {
        $tokens = NameHeuristics::requestTokens($this->name);

        foreach ($this->typeClassNames as $className) {
            $short = str_contains($className, '\\')
                ? substr($className, (int) strrpos($className, '\\') + 1)
                : $className;

            foreach (NameHeuristics::requestTokens($short) as $token) {
                if (!in_array($token, $tokens, true)) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }

    public function looksRequestScoped(): bool
    {
        return $this->requestTokens() !== [];
    }
}
