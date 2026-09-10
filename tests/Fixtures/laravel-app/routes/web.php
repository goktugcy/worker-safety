<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;

// Route registration at file scope runs once per worker boot.
return [
    '/dashboard' => [DashboardController::class, 'show'],
];
