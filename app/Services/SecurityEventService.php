<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\StructuredLoggerInterface;
use App\Core\Database;
use App\Core\RequestContext;
use App\Infrastructure\Logging\JsonErrorLogger;
use App\Security\Dors3\SecurityId;

final class SecurityEventService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?StructuredLoggerInterface $logger = null,
    ) {}

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $metadata
     */
    public function record(
        ?int $actorId,
        string $action,
        string $result,
        string $riskLevel = 'low',
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $credentialPublicId = null,
        array $metadata = [],
    ): string {
        $eventId = SecurityId::uuid();
        $requestId = RequestContext::requestId();
        $correlationId = $this->correlationId($requestId);
        $ip = RequestContext::ipAddress();
        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $authenticationLevel = $this->authenticationLevel();
        $instanceId = trim((string)env('APP_INSTANCE_ID', '')) ?: null;

        $this->db->query(
            'INSERT INTO security_events(
                event_id,occurred_at,actor_id,action,resource_type,resource_id,
                request_id,correlation_id,instance_id,ip,user_agent,authentication_level,
                credential_public_id,before_state,after_state,result,reason,risk_level,metadata
             ) VALUES(
                :event_id,NOW(),:actor_id,:action,:resource_type,:resource_id,
                :request_id,:correlation_id,:instance_id,:ip,:user_agent,:authentication_level,
                :credential_public_id,:before_state,:after_state,:result,:reason,:risk_level,:metadata
             )',
            [
                'event_id' => $eventId,
                'actor_id' => $actorId,
                'action' => mb_substr(trim($action), 0, 120),
                'resource_type' => $resourceType !== null ? mb_substr($resourceType, 0, 80) : null,
                'resource_id' => $resourceId !== null ? mb_substr($resourceId, 0, 128) : null,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'instance_id' => $instanceId,
                'ip' => $ip,
                'user_agent' => $userAgent !== '' ? mb_substr($userAgent, 0, 2048) : null,
                'authentication_level' => $authenticationLevel,
                'credential_public_id' => $credentialPublicId,
                'before_state' => $this->jsonOrNull($before),
                'after_state' => $this->jsonOrNull($after),
                'result' => $result,
                'reason' => $reason,
                'risk_level' => $riskLevel,
                'metadata' => $this->json($metadata),
            ]
        );

        ($this->logger ?? new JsonErrorLogger())->log(
            in_array($result, ['failure', 'blocked', 'rejected'], true) ? 'warning' : 'info',
            $action,
            [
                'event_type' => 'security_event',
                'event_id' => $eventId,
                'actor_user_id' => $actorId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'correlation_id' => $correlationId,
                'instance_id' => $instanceId,
                'authentication_level' => $authenticationLevel,
                'credential_public_id' => $credentialPublicId,
                'result' => $result,
                'reason' => $reason,
                'risk_level' => $riskLevel,
                'before' => $before,
                'after' => $after,
                'metadata' => $metadata,
            ]
        );

        // Wartownik jest projekcją obserwacyjną. Jego awaria nie może zmienić
        // wyniku ani przerwać operacji chronionej przez istniejące polityki.
        try {
            (new Dors3SentinelAlertService($this->db))->captureForEvent($eventId);
        } catch (\Throwable $error) {
            error_log('[3dors_sentinel_capture] event=' . $eventId . ' error=' . $error::class);
        }

        return $eventId;
    }

    private function authenticationLevel(): string
    {
        $context = $_SESSION['_authentication_context'] ?? null;
        if (!is_array($context)) {
            return isset($_SESSION['user_id']) ? 'session' : 'anonymous';
        }
        $factors = array_values(array_filter(array_map('strval', (array)($context['factors'] ?? []))));
        return $factors === [] ? 'session' : implode('+', array_slice($factors, 0, 4));
    }

    private function correlationId(string $fallback): string
    {
        $incoming = trim((string)($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,127}$/D', $incoming) === 1
            ? $incoming
            : $fallback;
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $value */
    private function jsonOrNull(?array $value): ?string
    {
        return $value === null ? null : $this->json($value);
    }
}
