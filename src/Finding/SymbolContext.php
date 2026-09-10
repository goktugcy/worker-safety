<?php

declare(strict_types=1);

namespace WorkerSafety\Finding;

/**
 * The code symbol a finding belongs to.
 *
 * Every field is optional: a finding can be reported at file level.
 */
final class SymbolContext
{
    public function __construct(
        public readonly ?string $class = null,
        public readonly ?string $method = null,
        public readonly ?string $property = null,
        public readonly ?string $variable = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->class === null
            && $this->method === null
            && $this->property === null
            && $this->variable === null;
    }

    /**
     * Human readable symbol path, e.g. `App\Ctx::setUser()` or `App\Ctx::$user`.
     */
    public function describe(): ?string
    {
        if ($this->class !== null && $this->property !== null) {
            return $this->class . '::$' . $this->property;
        }

        if ($this->class !== null && $this->method !== null) {
            return $this->class . '::' . $this->method . '()';
        }

        if ($this->class !== null) {
            return $this->class;
        }

        if ($this->method !== null) {
            return $this->method . '()';
        }

        if ($this->variable !== null) {
            return '$' . $this->variable;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'class' => $this->class,
            'method' => $this->method,
            'property' => $this->property,
            'variable' => $this->variable,
        ], static fn (?string $value): bool => $value !== null);
    }
}
