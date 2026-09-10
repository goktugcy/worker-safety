<?php

declare(strict_types=1);

namespace WorkerSafety\Framework;

/**
 * Result of framework detection.
 */
final class DetectedFramework
{
    public function __construct(
        public readonly Framework $framework,
        public readonly ?string $version = null,
        public readonly ?string $package = null,
    ) {
    }

    public static function none(): self
    {
        return new self(Framework::None);
    }

    public function identifier(): string
    {
        return $this->framework->value;
    }

    public function isLaravel(): bool
    {
        return $this->framework === Framework::Laravel;
    }

    public function isSymfony(): bool
    {
        return $this->framework === Framework::Symfony;
    }

    public function isKnown(): bool
    {
        return $this->framework !== Framework::None;
    }

    /**
     * e.g. `Laravel 12`, `Symfony 7`, `plain PHP`.
     */
    public function describe(): string
    {
        if ($this->version === null) {
            return $this->framework->label();
        }

        return $this->framework->label() . ' ' . $this->version;
    }

    /**
     * Framework label suitable for storing on a finding.
     */
    public function findingLabel(): ?string
    {
        return $this->isKnown() ? $this->framework->label() : null;
    }
}
