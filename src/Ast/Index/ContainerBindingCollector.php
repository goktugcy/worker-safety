<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use PhpParser\Node;

/**
 * Recognises service-container registrations for one framework.
 *
 * The core indexer knows nothing about Laravel or Symfony; framework adapters
 * contribute collectors, which is the seam a Symfony implementation plugs into.
 */
interface ContainerBindingCollector
{
    /**
     * @return iterable<ContainerBinding>
     */
    public function collect(Node $node, BindingContext $context): iterable;
}
