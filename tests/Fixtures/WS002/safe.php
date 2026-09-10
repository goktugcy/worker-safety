<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS002;

final class RequestReader
{
    public function path(): string
    {
        // Reading superglobals is normal and must never be reported.
        return (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public function page(): int
    {
        return (int) ($_GET['page'] ?? 1);
    }

    public function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    public function sessionUser(): ?string
    {
        $_SESSION['seen'] = true;

        return isset($_SESSION['user']) ? (string) $_SESSION['user'] : null;
    }
}
