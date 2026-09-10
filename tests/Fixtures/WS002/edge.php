<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS002;

function readOnlyGlobal(): ?object
{
    global $container;

    return $container;
}

function repeatedGlobalWrites(): void
{
    $GLOBALS['counter'] = 0;
    $GLOBALS['counter'] = 1;
    $GLOBALS['counter'] = 2;
}
