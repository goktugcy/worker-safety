<?php

declare(strict_types=1);

namespace WorkerSafety\Framework\Laravel;

use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Framework\FrameworkAdapter;
use WorkerSafety\Framework\Laravel\Rule\ContainerSingletonMutableStateRule;
use WorkerSafety\Framework\Laravel\Rule\ScopedBindingCandidateRule;

/**
 * Laravel awareness: container binding collection, the two container rules and
 * scan defaults that match the standard skeleton.
 */
final class LaravelAdapter implements FrameworkAdapter
{
    public function identifier(): string
    {
        return Framework::Laravel->value;
    }

    public function supports(DetectedFramework $framework): bool
    {
        return $framework->isLaravel();
    }

    public function rules(): array
    {
        $inspector = new SharedBindingInspector();

        return [
            new ContainerSingletonMutableStateRule($inspector),
            new ScopedBindingCandidateRule($inspector),
        ];
    }

    public function bindingCollectors(): array
    {
        return [new LaravelContainerBindingCollector()];
    }

    public function defaultPaths(): array
    {
        return ['app', 'bootstrap', 'config', 'database', 'routes'];
    }

    public function defaultExcludes(): array
    {
        return [
            'bootstrap/cache',
            'public',
            'resources/views',
            '*.blade.php',
        ];
    }
}
