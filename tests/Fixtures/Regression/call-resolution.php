<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

use function putenv as changeEnv;

class Calls
{
    public function mutate(): void
    {
        // An aliased import is still putenv().
        changeEnv('USER=test');
    }

    public function query(): string
    {
        // A zero locale argument reads without writing.
        return (string) setlocale(LC_ALL, 0);
    }
}
