<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Replay\StaticSafe;

/**
 * An ordinary request-scoped object, and the point of the whole replay feature.
 *
 * Nothing here is unsafe. There is no static property, no global, no singleton
 * holder and no container binding — just a mutable instance property, which is
 * what almost every value object in every application looks like. The static
 * analyzer reports zero findings on this file, and that is the correct answer:
 * whether it leaks depends entirely on who constructs it and how long they keep
 * it, and neither of those facts is in this file.
 *
 * tests/Fixtures/Replay/leaky-worker/server.php constructs exactly one of these
 * and reuses it for every request. That is the leak, and only running it can
 * show it.
 */
final class RequestContext
{
    private ?string $user = null;

    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    public function user(): ?string
    {
        return $this->user;
    }

    public function forget(): void
    {
        $this->user = null;
    }
}
