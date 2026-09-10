<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\UserContext;
use Illuminate\Support\Facades\Event;

final class DashboardController
{
    /**
     * WS001 + WS008: a per-request render cache that nothing ever clears.
     *
     * @var array<string, string>
     */
    private static array $renderedWidgets = [];

    public function __construct(private readonly UserContext $context)
    {
    }

    public function show(User $user): string
    {
        $this->context->user = $user;

        // WS009: listener registration on a request path.
        Event::listen('dashboard.viewed', function () use ($user): void {
            unset($user);
        });

        self::$renderedWidgets[$user->email] = 'rendered';

        return 'ok';
    }
}
