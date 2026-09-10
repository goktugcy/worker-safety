<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use WorkerSafety\Support\SourceFile;

/**
 * Where in the source a potential container binding was seen.
 */
final class BindingContext
{
    public function __construct(
        public readonly SourceFile $file,
        public readonly ?string $currentClass = null,
        public readonly ?string $parentClass = null,
        public readonly ?string $currentMethod = null,
    ) {
    }
}
