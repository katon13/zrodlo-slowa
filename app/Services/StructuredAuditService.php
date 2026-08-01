<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\StructuredLoggerInterface;
use App\Core\Database;
use App\Core\RequestContext;
use App\Infrastructure\Logging\JsonErrorLogger;

final class StructuredAuditService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?StructuredLoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public function record(
        ?int $actorUserId,
        string $operation,
        array $details = [],
        string $result = 'success',
        ?int $subjectUserId = null,
    ): void {
        $event = [
            'event_type' => 'audit',
            'user_id' => $subjectUserId ?? $actorUserId,
            'actor_user_id' => $actorUserId,
            'actor_role' => (string)($_SESSION['role'] ?? 'system'),
            'operation' => $operation,
            'ip' => RequestContext::ipAddress(),
            'request_id' => RequestContext::requestId(),
            'result' => $result,
            'details' => $details,
        ];

        $this->db->query(
            'INSERT INTO admin_audit_logs(user_id,action,payload,created_at)
             VALUES(:user,:action,:payload,NOW())',
            [
                'user' => $actorUserId,
                'action' => mb_substr($operation, 0, 120),
                'payload' => json_encode(
                    $event,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ]
        );
        ($this->logger ?? new JsonErrorLogger())->log('info', $operation, $event);
    }
}
