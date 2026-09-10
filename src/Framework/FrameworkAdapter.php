<?php

declare(strict_types=1);

namespace WorkerSafety\Framework;

use WorkerSafety\Ast\Index\ContainerBindingCollector;
use WorkerSafety\Rule\Rule;

/**
 * Framework specific extension point.
 *
 * An adapter contributes rules, container-binding collectors and sensible
 * default scan paths, without the core analyzer knowing the framework exists.
 */
interface FrameworkAdapter
{
    public function identifier(): string;

    public function supports(DetectedFramework $framework): bool;

    /**
     * @return list<Rule>
     */
    public function rules(): array;

    /**
     * @return list<ContainerBindingCollector>
     */
    public function bindingCollectors(): array;

    /**
     * Paths scanned when neither the CLI nor the config file names any.
     *
     * @return list<string>
     */
    public function defaultPaths(): array;

    /**
     * Framework specific directories that should never be scanned.
     *
     * @return list<string>
     */
    public function defaultExcludes(): array;
}
