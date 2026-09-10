<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

enum ClassKind: string
{
    case ClassType = 'class';
    case Interface = 'interface';
    case Trait = 'trait';
    case Enum = 'enum';
}
