<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Atomowo unieważnia bieżący stan 3DORS Mobile Admin bez kasowania historii. */
final class AdminMobileSecurityResetService
{
    public function __construct(private readonly Database $db) {}

    /**
     * Metoda może działać wewnątrz istniejącej transakcji recovery.
     *
     * @return array{devices_revoked:int,credentials_revoked:int,tokens_revoked:int,pending_requests_cancelled:int,deferred_operations_cancelled:int,enrollments_cancelled:int}
     */
    public function revokeAll(int $adminId, string $reason): array
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') {
            throw new \InvalidArgumentException('Powód unieważnienia zabezpieczeń mobilnych jest wymagany.');
        }

        return $this->db->transaction(function (Database $db) use ($adminId, $reason): array {
            $tokensRevoked = (int)$db->cell(
                'SELECT COUNT(*)
                 FROM security_mobile_credentials c
                 JOIN security_mobile_devices d ON d.id=c.device_id
                 WHERE d.user_id=:admin AND d.application_variant=\'admin\'
                   AND c.api_token_hash IS NOT NULL',
                ['admin' => $adminId]
            );

            $deferredCancelled = $db->query(
                'UPDATE security_mobile_deferred_operations
                 SET status=\'cancelled\',failure_reason=:reason
                 WHERE status=\'pending\'
                   AND approval_request_id IN (
                       SELECT id FROM security_mobile_approval_requests
                       WHERE user_id=:admin AND application_variant=\'admin\'
                   )',
                ['admin' => $adminId, 'reason' => $reason]
            )->rowCount();

            $requestsCancelled = $db->query(
                'UPDATE security_mobile_approval_requests
                 SET status=\'cancelled\'
                 WHERE user_id=:admin AND application_variant=\'admin\' AND status=\'pending\'',
                ['admin' => $adminId]
            )->rowCount();

            $enrollmentsCancelled = $db->query(
                'UPDATE security_mobile_enrollments
                 SET status=\'cancelled\'
                 WHERE user_id=:admin AND application_variant=\'admin\'
                   AND status IN (\'pending\',\'completed\')',
                ['admin' => $adminId]
            )->rowCount();

            $credentialsRevoked = $db->query(
                'UPDATE security_mobile_credentials
                 SET status=\'revoked\',revoked_at=COALESCE(revoked_at,NOW()),
                     revocation_reason=:reason,api_token_hash=NULL,api_token_expires_at=NULL
                 WHERE device_id IN (
                     SELECT id FROM security_mobile_devices
                     WHERE user_id=:admin AND application_variant=\'admin\'
                 )
                   AND (status<>\'revoked\' OR api_token_hash IS NOT NULL)',
                ['admin' => $adminId, 'reason' => $reason]
            )->rowCount();

            $devicesRevoked = $db->query(
                'UPDATE security_mobile_devices
                 SET status=\'revoked\',revoked_at=COALESCE(revoked_at,NOW()),revoked_by=:admin,
                     revocation_reason=:reason
                 WHERE user_id=:admin AND application_variant=\'admin\' AND status<>\'revoked\'',
                ['admin' => $adminId, 'reason' => $reason]
            )->rowCount();

            return [
                'devices_revoked' => $devicesRevoked,
                'credentials_revoked' => $credentialsRevoked,
                'tokens_revoked' => $tokensRevoked,
                'pending_requests_cancelled' => $requestsCancelled,
                'deferred_operations_cancelled' => $deferredCancelled,
                'enrollments_cancelled' => $enrollmentsCancelled,
            ];
        });
    }
}
