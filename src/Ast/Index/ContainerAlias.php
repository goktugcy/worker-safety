<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use WorkerSafety\Finding\Location;

/**
 * An `$app->alias($abstract, $alias)` registration.
 *
 * The container resolves an alias to its abstract before touching the instance
 * map, so a scoped registration under an alias really does flush the abstract.
 */
final class ContainerAlias
{
    public function __construct(
        public readonly string $abstract,
        public readonly string $alias,
        public readonly Location $location,
    ) {
    }
}
