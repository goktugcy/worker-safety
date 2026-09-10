<?php

declare(strict_types=1);

namespace WorkerSafety\Framework\Symfony;

use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Framework\FrameworkAdapter;

/**
 * Symfony awareness: scan defaults only.
 *
 * The adapter exists so that Symfony-specific rules and a Symfony container
 * binding collector have a home; v1 ships none, and contributes no rules rather
 * than pretending to analyze something it does not understand yet.
 */
final class SymfonyAdapter implements FrameworkAdapter
{
    public function identifier(): string
    {
        return Framework::Symfony->value;
    }

    public function supports(DetectedFramework $framework): bool
    {
        return $framework->isSymfony();
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
        return ['src', 'config'];
    }

    public function defaultExcludes(): array
    {
        return [
            'var',
            'public/bundles',
            'templates',
            '*.twig',
        ];
    }
}
