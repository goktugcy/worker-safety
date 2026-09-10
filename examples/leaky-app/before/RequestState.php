<?php

declare(strict_types=1);

namespace Example\Before;

final class RequestState
{
    public ?object $user = null;

    public ?string $locale = null;
}
