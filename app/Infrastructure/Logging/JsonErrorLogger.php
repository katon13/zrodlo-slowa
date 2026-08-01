<?php
declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Contracts\StructuredLoggerInterface;
use App\Core\RequestContext;

final class JsonErrorLogger implements StructuredLoggerInterface
{
    public function log(string $level, string $operation, array $context): void
    {
        $record = [
            'timestamp' => gmdate('c'),
            'level' => strtolower($level),
            'user_id' => $_SESSION['user_id'] ?? null,
            'actor_user_id' => $_SESSION['user_id'] ?? null,
            'operation' => $operation,
            'ip' => RequestContext::ipAddress(),
            'request_id' => RequestContext::requestId(),
            'result' => $context['result'] ?? (strtolower($level) === 'error' ? 'failure' : 'success'),
        ] + $context;

        error_log((string)json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }
}
