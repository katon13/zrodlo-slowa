<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class Dors3SettingsService
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Database $db,
        private readonly array $config,
    ) {}

    /** @return array<string, mixed> */
    public function current(): array
    {
        $row = $this->db->one('SELECT * FROM security_settings WHERE id=1');
        if ($row === null) {
            throw new \RuntimeException('Brak migracji ustawień 3DORS.');
        }

        $environmentMode = (string)($this->config['mode'] ?? 'prepare');
        $databaseMode = (string)($row['dors3_mode'] ?? 'prepare');
        if ($environmentMode !== $databaseMode) {
            throw new \RuntimeException('Tryb 3DORS w środowisku i bazie danych jest niespójny.');
        }

        $settings = [
            'mode' => $databaseMode,
            'fido2_enabled' => (int)$row['fido2_enabled'] === 1,
            'fido2_required' => (int)$row['fido2_required'] === 1,
            'critical_step_up' => (string)$row['critical_step_up_method'],
            'physical_approval' => (string)$row['physical_approval'],
            'admin_idle_timeout_seconds' => (int)$row['admin_idle_timeout_seconds'],
            'admin_session_max_seconds' => (int)$row['admin_session_max_seconds'],
            'step_up_ttl_seconds' => (int)$row['step_up_ttl_seconds'],
            'challenge_ttl_seconds' => (int)$row['challenge_ttl_seconds'],
            'required_gate' => $this->decodeJson($row['required_gate'] ?? null),
            'updated_at' => (string)$row['updated_at'],
            'reason' => $row['reason'] !== null ? (string)$row['reason'] : null,
            'mobile' => is_array($this->config['mobile'] ?? null) ? $this->config['mobile'] : [],
        ];

        $this->assertSafeCombination($settings);
        return $settings;
    }

    /** @return array<string, bool> */
    public function requiredGate(int $adminId): array
    {
        $settings = $this->current();
        $stored = is_array($settings['required_gate'] ?? null) ? $settings['required_gate'] : [];
        $credentials = $this->db->all(
            'SELECT credential_role,tested_at,status FROM webauthn_credentials WHERE user_id=:user',
            ['user' => $adminId]
        );
        $primaryTested = false;
        $backupTested = false;
        foreach ($credentials as $credential) {
            if ((string)$credential['status'] !== 'active' || empty($credential['tested_at'])) {
                continue;
            }
            $primaryTested = $primaryTested || (string)$credential['credential_role'] === 'primary';
            $backupTested = $backupTested || (string)$credential['credential_role'] === 'backup';
        }
        $codes = $this->db->one(
            'SELECT COUNT(*) AS total, COUNT(confirmed_at) AS confirmed
             FROM security_recovery_codes
             WHERE user_id=:user AND used_at IS NULL AND revoked_at IS NULL',
            ['user' => $adminId]
        ) ?? ['total' => 0, 'confirmed' => 0];

        return [
            'primary_key_tested' => $primaryTested,
            'backup_key_tested' => $backupTested,
            'ten_recovery_codes' => (int)$codes['total'] === 10,
            'recovery_codes_confirmed' => (int)$codes['confirmed'] === 10,
            'recovery_cli_tested' => (bool)($stored['recovery_cli_tested'] ?? false),
            'postgres_backup_completed' => (bool)($stored['postgres_backup_completed'] ?? false),
            'cross_instance_tested' => (bool)($stored['cross_instance_tested'] ?? false),
            'replay_tested' => (bool)($stored['replay_tested'] ?? false),
            'bad_origin_tested' => (bool)($stored['bad_origin_tested'] ?? false),
            'explicit_user_approval' => (bool)($stored['explicit_user_approval'] ?? false),
        ];
    }

    /** @param array<string, mixed> $settings */
    private function assertSafeCombination(array $settings): void
    {
        $environmentFido = (bool)($this->config['fido2_enabled'] ?? false);
        $environmentRequired = (bool)($this->config['fido2_required'] ?? false);
        $environmentWebAuthn = (bool)($this->config['webauthn']['enabled'] ?? false);
        if ($settings['mode'] === 'prepare') {
            if (
                $settings['fido2_enabled']
                || $settings['fido2_required']
                || $environmentFido
                || $environmentRequired
                || $environmentWebAuthn
                || $settings['critical_step_up'] !== 'password'
            ) {
                throw new \RuntimeException('Niebezpieczna kombinacja ustawień 3DORS prepare.');
            }
        }
        if ($settings['mode'] === 'required' && !($settings['fido2_enabled'] && $settings['fido2_required'])) {
            throw new \RuntimeException('Tryb required bez wymaganego FIDO2 jest zabroniony.');
        }
        if ($settings['physical_approval'] !== 'disabled') {
            throw new \RuntimeException('Fizyczna Brama Zgody nie ma aktywnego dostawcy.');
        }
        $mobile = is_array($settings['mobile'] ?? null) ? $settings['mobile'] : [];
        if ((string)($mobile['mode'] ?? 'disabled') === 'required') {
            if (!(bool)($mobile['enabled'] ?? false) || (!(bool)($mobile['admin_app_enabled'] ?? false) && !(bool)($mobile['author_app_enabled'] ?? false))) {
                throw new \RuntimeException('Tryb required 3DORS Mobile bez aktywnego wariantu aplikacji jest zabroniony.');
            }
            if ((bool)($mobile['admin_app_enabled'] ?? false)) {
                \App\Security\Dors3\MobileApprovalConfiguration::isEnabled($mobile, 'admin', 'payout_approval');
                \App\Security\Dors3\MobileApprovalConfiguration::isEnabled($mobile, 'admin', 'admin_critical_approval');
            }
            if ((bool)($mobile['author_app_enabled'] ?? false)) {
                \App\Security\Dors3\MobileApprovalConfiguration::isEnabled($mobile, 'author', 'article_submit_approval');
                \App\Security\Dors3\MobileApprovalConfiguration::isEnabled($mobile, 'author', 'article_publish_approval');
            }
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
