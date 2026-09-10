<?php

declare(strict_types=1);

namespace WorkerSafety\Framework;

/**
 * Fallback adapter for projects with no recognised framework.
 */
final class PlainPhpAdapter implements FrameworkAdapter
{
    public function identifier(): string
    {
        return Framework::None->value;
    }

    public function supports(DetectedFramework $framework): bool
    {
        return !$framework->isKnown();
    }

    public function rules(): array
    {
        return [];
    }

    public function bindingCollectors(): array
    {
        return [];
    }

    public function defaultPaths(): array
    {
        return ['src', 'lib', 'app'];
    }

    public function defaultExcludes(): array
    {
        return [];
    }
}
