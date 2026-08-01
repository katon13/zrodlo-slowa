<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\Dors3\SecurityId;

final class AdminRecoveryService
{
    public function __construct(
        private readonly Database $db,
        private readonly RecoveryCodeService $codes,
        private readonly SecurityEventService $events,
        private readonly string $reportDirectory,
    ) {}

    /** @return array<string, mixed> */
    public function recover(int $adminId, string $recoveryCode, string $confirmation, string $reason): array
    {
        $expected = 'ODZYSKUJE ADMINA ' . $adminId . ' I UNIEWAZNIAM KLUCZE 3DORS';
        if (!hash_equals($expected, trim($confirmation))) {
            throw new \RuntimeException('Pełny tekst potwierdzenia jest nieprawidłowy.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 12 || mb_strlen($reason) > 1000) {
            throw new \RuntimeException('Powód odzyskiwania musi mieć od 12 do 1000 znaków.');
        }
        $admin = $this->db->one(
            'SELECT u.id,u.login_name,u.email,u.session_version
             FROM users u
             JOIN user_roles ur ON ur.user_id=u.id AND ur.role=\'admin\'
             WHERE u.id=:id LIMIT 1',
            ['id' => $adminId]
        );
        if ($admin === null) {
            throw new \RuntimeException('Nie znaleziono wskazanego administratora.');
        }

        $reportId = SecurityId::uuid();
        $beforeSettings = $this->db->one('SELECT * FROM security_settings WHERE id=1');
        $revokedCredentials = 0;
        $deletedSessions = 0;
        $this->codes->consumeForRecovery(
            $adminId,
            $recoveryCode,
            function (Database $db, array $usedCode) use (
                $adminId,
                $reason,
                $reportId,
                $beforeSettings,
                &$revokedCredentials,
                &$deletedSessions
            ): void {
                $revokedCredentials = $db->query(
                    'UPDATE webauthn_credentials
                     SET status=\'revoked\',revoked_at=NOW(),revoked_by=:admin,
                         revocation_reason=:reason,updated_at=NOW()
                     WHERE user_id=:admin AND status<>\'revoked\'',
                    ['admin' => $adminId, 'reason' => $reason]
                )->rowCount();
                $deletedSessions = $db->query(
                    'DELETE FROM sessions WHERE user_id=:admin',
                    ['admin' => $adminId]
                )->rowCount();
                $db->query(
                    'UPDATE users SET session_version=session_version+1,updated_at=NOW() WHERE id=:admin',
                    ['admin' => $adminId]
                );
                $db->query(
                    'UPDATE security_settings
                     SET dors3_mode=CASE WHEN dors3_mode=\'required\' THEN \'test\' ELSE dors3_mode END,
                         fido2_required=0,
                         critical_step_up_method=\'password\',
                         updated_by=:admin,updated_at=NOW(),reason=:reason
                     WHERE id=1',
                    ['admin' => $adminId, 'reason' => 'Recovery ' . $reportId . ': ' . $reason]
                );
                $afterSettings = $db->one('SELECT * FROM security_settings WHERE id=1');
                $this->events->record(
                    $adminId,
                    'security.recovery.executed',
                    'success',
                    'critical',
                    'user',
                    (string)$adminId,
                    is_array($beforeSettings) ? $beforeSettings : null,
                    is_array($afterSettings) ? $afterSettings : null,
                    $reason,
                    null,
                    [
                        'report_id' => $reportId,
                        'used_recovery_code_public_id' => (string)$usedCode['public_id'],
                        'sessions_ended' => $deletedSessions,
                        'credentials_revoked' => $revokedCredentials,
                        'password_changed' => false,
                    ]
                );
            }
        );

        $report = [
            'report_id' => $reportId,
            'created_at' => gmdate('c'),
            'admin_id' => $adminId,
            'admin_login' => $admin['login_name'],
            'reason' => $reason,
            'credentials_revoked' => $revokedCredentials,
            'sessions_ended' => $deletedSessions,
            'session_version_before' => (int)$admin['session_version'],
            'session_version_after' => (int)$admin['session_version'] + 1,
            'password_changed' => false,
            'required_downgrade_target' => 'test',
            'operator_next_step' => 'If recovery changed required to test, set DORS3_MODE=test and DORS3_FIDO2_REQUIRED=false before restarting the app.',
        ];
        $this->writeReport($reportId, $report);
        return $report;
    }

    /** @param array<string, mixed> $report */
    private function writeReport(string $reportId, array $report): void
    {
        if (!is_dir($this->reportDirectory) && !mkdir($this->reportDirectory, 0700, true) && !is_dir($this->reportDirectory)) {
            throw new \RuntimeException('Nie udało się utworzyć katalogu raportów odzyskiwania.');
        }
        $path = rtrim($this->reportDirectory, '/\\') . DIRECTORY_SEPARATOR . $reportId . '.json';
        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Nie udało się zapisać raportu odzyskiwania.');
        }
        @chmod($path, 0600);
    }
}
