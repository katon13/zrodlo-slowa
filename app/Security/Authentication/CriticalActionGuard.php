<?php
declare(strict_types=1);

namespace App\Security\Authentication;

use App\Core\Session;

final class CriticalActionGuard
{
    public function __construct(private readonly int $maxAgeSeconds = 600)
    {
        if ($maxAgeSeconds < 30 || $maxAgeSeconds > 3600) {
            throw new \InvalidArgumentException('Nieprawidłowy czas ważności ponownego uwierzytelnienia.');
        }
    }

    public function assertRecentlyVerified(Session $session): void
    {
        if (!$session->hasRecentStrongAuthentication($this->maxAgeSeconds)) {
            throw new \RuntimeException('Ta operacja wymaga ponownego silnego uwierzytelnienia.');
        }
    }
}
