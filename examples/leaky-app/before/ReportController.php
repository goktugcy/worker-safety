<?php

declare(strict_types=1);

namespace Example\Before;

use Illuminate\Support\Facades\Event;

final class ReportController
{
    public function show(string $id): string
    {
        // A new listener on every request; the old ones keep firing, each
        // holding on to the $id of the request that registered it.
        Event::listen('report.viewed', static function () use ($id): void {
            error_log('viewed ' . $id);
        });

        // Ends the worker process, not just this request.
        if ($id === '') {
            exit(1);
        }

        return 'report ' . $id;
    }
}
