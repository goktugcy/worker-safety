<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS002;

function bootUser(object $user): void
{
    global $currentUser;

    $currentUser = $user;
}

function rememberTenant(string $tenant): void
{
    $GLOBALS['tenant'] = $tenant;
}

function overrideInput(string $value): void
{
    $_GET['page'] = $value;
    $_POST['page'] = $value;
}
