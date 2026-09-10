<?php

declare(strict_types=1);

namespace WorkerSafety\Framework\Laravel;

use WorkerSafety\Ast\Index\ClassShape;
use WorkerSafety\Ast\Index\ContainerBinding;
use WorkerSafety\Ast\Index\PropertyShape;

/**
 * One shared container binding together with the shape of the class it binds.
 */
final class SharedBindingInspection
{
    /**
     * @param list<PropertyShape> $mutableProperties
     * @param list<PropertyShape> $requestScopedProperties
     */
    public function __construct(
        public readonly ContainerBinding $binding,
        public readonly ClassShape $class,
        public readonly array $mutableProperties,
        public readonly array $requestScopedProperties,
    ) {
    }

    public function hasMutableState(): bool
    {
        return $this->mutableProperties !== [];
    }

    public function looksRequestScoped(): bool
    {
        return $this->requestScopedProperties !== [];
    }

    /**
     * `$user, $locale` — for use inside a message.
     */
    public function describeProperties(int $limit = 4): string
    {
        $names = array_map(
            static fn (PropertyShape $property): string => '$' . $property->name,
            $this->mutableProperties,
        );

        $shown = implode(', ', array_slice($names, 0, $limit));

        return count($names) > $limit ? $shown . ', …' : $shown;
    }

    /**
     * @return list<string>
     */
    public function requestTokens(): array
    {
        $tokens = [];

        foreach ($this->requestScopedProperties as $property) {
            foreach ($property->requestTokens() as $token) {
                if (!in_array($token, $tokens, true)) {
                    $tokens[] = $token;
                }
            }
        }

        return $tokens;
    }
}
