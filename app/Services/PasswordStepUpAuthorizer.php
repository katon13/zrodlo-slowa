<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\RequestContext;
use App\Security\Dors3\ActionFingerprint;
use App\Security\Dors3\ApprovalContext;
use App\Security\Dors3\ApprovalRequest;
use App\Security\Dors3\ApprovalResponse;
use App\Security\Dors3\ApprovalResult;
use App\Security\Dors3\CriticalOperationAuthorizerInterface;
use App\Security\Dors3\SecurityId;

final class PasswordStepUpAuthorizer implements CriticalOperationAuthorizerInterface
{
    public function __construct(
        private readonly Database $db,
        private readonly Dors3SettingsService $settings,
        private readonly SecurityEventService $events,
    ) {}

    public function begin(ApprovalContext $context): ApprovalRequest
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Password step-up nie jest dostępny w aktualnym trybie 3DORS.');
        }
        $this->assertFailureLimit($context);
        $settings = $this->settings->current();
        $requestId = RequestContext::requestId();
        $expiresAt = time() + (int)$settings['step_up_ttl_seconds'];
        $fingerprint = ActionFingerprint::calculate($context, $requestId, $expiresAt);
        $publicId = SecurityId::uuid();
        $correlationId = $this->correlationId($requestId);

        $this->db->query(
            'INSERT INTO security_step_up_authorizations(
                public_id,user_id,operation,action_fingerprint,method,context,
                request_id,correlation_id,expires_at,created_at
             ) VALUES(
                :public_id,:user_id,:operation,:fingerprint,\'password\',:context,
                :request_id,:correlation_id,:expires_at,NOW()
             )',
            [
                'public_id' => $publicId,
                'user_id' => $context->actorId,
                'operation' => mb_substr($context->operation, 0, 120),
                'fingerprint' => $fingerprint,
                'context' => ActionFingerprint::canonicalJson($context->toArray()),
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
            ]
        );
        $this->events->record(
            $context->actorId,
            'security.step_up.started',
            'success',
            'high',
            $context->resourceType,
            $context->resourceId,
            $context->before,
            $context->after,
            null,
            null,
            ['operation' => $context->operation, 'authorization_public_id' => $publicId, 'fingerprint' => $fingerprint]
        );

        return new ApprovalRequest(
            $publicId,
            $context,
            $fingerprint,
            'password',
            $requestId,
            $expiresAt,
        );
    }

    public function verify(ApprovalResponse $response): ApprovalResult
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Password step-up nie jest dostępny w aktualnym trybie 3DORS.');
        }

        $result = $this->db->transaction(function (Database $db) use ($response): ApprovalResult|\RuntimeException {
            $row = $db->one(
                'SELECT * FROM security_step_up_authorizations WHERE public_id=:public_id FOR UPDATE',
                ['public_id' => $response->request->publicId]
            );
            if ($row === null) {
                throw new \RuntimeException('Nie znaleziono autoryzacji operacji krytycznej.');
            }

            $context = $response->request->context;
            $expectedFingerprint = ActionFingerprint::calculate(
                $context,
                $response->request->requestId,
                $response->request->expiresAt
            );
            $validEnvelope = (int)$row['user_id'] === $context->actorId
                && hash_equals((string)$row['action_fingerprint'], $response->request->actionFingerprint)
                && hash_equals((string)$row['action_fingerprint'], $expectedFingerprint)
                && (string)$row['operation'] === $context->operation
                && (string)$row['request_id'] === $response->request->requestId;
            $expired = $response->request->expiresAt < time()
                || strtotime((string)$row['expires_at'] . ' UTC') < time();
            $alreadyUsed = !empty($row['consumed_at']) || !empty($row['invalidated_at']);

            if (!$validEnvelope || $expired || $alreadyUsed) {
                if (!$alreadyUsed) {
                    $db->query(
                        'UPDATE security_step_up_authorizations SET invalidated_at=NOW() WHERE id=:id',
                        ['id' => (int)$row['id']]
                    );
                }
                $this->events->record(
                    $context->actorId,
                    'security.step_up.rejected',
                    'rejected',
                    'high',
                    $context->resourceType,
                    $context->resourceId,
                    $context->before,
                    $context->after,
                    !$validEnvelope ? 'action_fingerprint_mismatch' : ($expired ? 'expired' : 'replayed'),
                    null,
                    ['operation' => $context->operation, 'authorization_public_id' => $response->request->publicId]
                );
                return new \RuntimeException('Potwierdzenie operacji jest nieważne, wygasło albo zostało już użyte.');
            }

            $user = $db->one(
                'SELECT u.password_hash
                 FROM users u
                 WHERE u.id=:id AND u.status=\'active\'
                 LIMIT 1',
                ['id' => $context->actorId]
            );
            $validPassword = $user !== null && password_verify(
                $response->proof . env('PASSWORD_PEPPER', ''),
                (string)$user['password_hash']
            );
            if (!$validPassword) {
                $db->query(
                    'UPDATE security_step_up_authorizations SET invalidated_at=NOW() WHERE id=:id',
                    ['id' => (int)$row['id']]
                );
                $this->events->record(
                    $context->actorId,
                    'security.step_up.failed',
                    'failure',
                    'high',
                    $context->resourceType,
                    $context->resourceId,
                    $context->before,
                    $context->after,
                    'invalid_password',
                    null,
                    ['operation' => $context->operation, 'authorization_public_id' => $response->request->publicId]
                );
                return new \RuntimeException('Aktualne hasło administratora jest nieprawidłowe.');
            }

            $updated = $db->query(
                'UPDATE security_step_up_authorizations
                 SET consumed_at=NOW()
                 WHERE id=:id AND consumed_at IS NULL AND invalidated_at IS NULL',
                ['id' => (int)$row['id']]
            )->rowCount();
            if ($updated !== 1) {
                throw new \RuntimeException('Autoryzacja została już wykorzystana.');
            }
            $this->events->record(
                $context->actorId,
                'security.step_up.approved',
                'success',
                'high',
                $context->resourceType,
                $context->resourceId,
                $context->before,
                $context->after,
                null,
                null,
                [
                    'operation' => $context->operation,
                    'authorization_public_id' => $response->request->publicId,
                    'fingerprint' => $expectedFingerprint,
                ]
            );

            return new ApprovalResult(true, $response->request->publicId, 'password', 'approved');
        });
        if ($result instanceof \RuntimeException) {
            throw $result;
        }
        return $result;
    }

    public function isAvailable(): bool
    {
        try {
            $settings = $this->settings->current();
            return (string)$settings['mode'] === 'prepare'
                && (string)$settings['critical_step_up'] === 'password'
                && !$settings['fido2_required'];
        } catch (\Throwable) {
            return false;
        }
    }

    public function providerName(): string
    {
        return 'password';
    }

    private function correlationId(string $fallback): string
    {
        $incoming = trim((string)($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{7,127}$/D', $incoming) === 1
            ? $incoming
            : $fallback;
    }

    private function assertFailureLimit(ApprovalContext $context): void
    {
        $failures = (int)$this->db->cell(
            'SELECT COUNT(*) FROM security_events
             WHERE actor_id=:actor AND action=\'security.step_up.failed\'
               AND occurred_at >= ' . $this->db->nowMinus(15, 'minute'),
            ['actor' => $context->actorId]
        );
        if ($failures < 5) {
            return;
        }
        $this->events->record(
            $context->actorId,
            'security.step_up.blocked',
            'blocked',
            'high',
            $context->resourceType,
            $context->resourceId,
            $context->before,
            $context->after,
            'too_many_invalid_password_attempts',
            null,
            ['operation' => $context->operation, 'window_seconds' => 900, 'failure_limit' => 5]
        );
        throw new \RuntimeException('Zbyt wiele błędnych potwierdzeń. Spróbuj ponownie za 15 minut.');
    }
}
