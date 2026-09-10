<?php

declare(strict_types=1);

namespace Example\After;

final class ReportController
{
    public function __construct(private readonly UserContext $context)
    {
    }

    public function show(string $id): string
    {
        if ($id === '') {
            // Return a response instead of killing the worker.
            return 'not found';
        }

        return 'report ' . $id . ' for ' . (string) ($this->context->current()?->name ?? 'guest');
    }
}
