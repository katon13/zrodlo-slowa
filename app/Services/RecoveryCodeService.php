<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\RequestContext;
use App\Security\Dors3\SecurityId;

final class RecoveryCodeService
{
    private const CODE_COUNT = 10;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly Database $db,
        private readonly SecurityEventService $events,
    ) {}

    /** @return array{batch_public_id:string,codes:list<string>} */
    public function generate(int $adminId): array
    {
        $batchPublicId = SecurityId::uuid();
        $codes = [];
        $rows = [];
        for ($position = 1; $position <= self::CODE_COUNT; $position++) {
            $normalized = $this->randomCode(20);
            $codes[] = 'D3-' . implode('-', str_split($normalized, 5));
            $rows[] = [
                'public_id' => SecurityId::uuid(),
                'position' => $position,
                'hash' => password_hash($normalized . env('PASSWORD_PEPPER', ''), PASSWORD_DEFAULT),
            ];
        }

        $this->db->transaction(function (Database $db) use ($adminId, $batchPublicId, $rows): void {
            $db->query(
                'UPDATE security_recovery_codes SET revoked_at=NOW()
                 WHERE user_id=:user AND used_at IS NULL AND revoked_at IS NULL',
                ['user' => $adminId]
            );
            foreach ($rows as $row) {
                $db->query(
                    'INSERT INTO security_recovery_codes(
                        public_id,user_id,batch_public_id,position,code_hash,created_at
                     ) VALUES(:public_id,:user,:batch,:position,:code_hash,NOW())',
                    [
                        'public_id' => $row['public_id'],
                        'user' => $adminId,
                        'batch' => $batchPublicId,
                        'position' => $row['position'],
                        'code_hash' => $row['hash'],
                    ]
                );
            }
            $this->events->record(
                $adminId,
                'security.recovery_codes.generated',
                'success',
                'high',
                'recovery_code_batch',
                $batchPublicId,
                null,
                ['count' => self::CODE_COUNT, 'confirmed' => false],
                null,
                null,
                ['previous_batches_revoked' => true]
            );
        });

        return ['batch_public_id' => $batchPublicId, 'codes' => $codes];
    }

    public function confirmSaved(int $adminId, string $batchPublicId): void
    {
        $this->db->transaction(function (Database $db) use ($adminId, $batchPublicId): void {
            $count = (int)$db->cell(
                'SELECT COUNT(*) FROM security_recovery_codes
                 WHERE user_id=:user AND batch_public_id=:batch
                   AND used_at IS NULL AND revoked_at IS NULL',
                ['user' => $adminId, 'batch' => $batchPublicId]
            );
            if ($count !== self::CODE_COUNT) {
                throw new \RuntimeException('Nie znaleziono kompletnego, aktywnego zestawu kodów odzyskiwania.');
            }
            $db->query(
                'UPDATE security_recovery_codes SET confirmed_at=COALESCE(confirmed_at,NOW())
                 WHERE user_id=:user AND batch_public_id=:batch
                   AND used_at IS NULL AND revoked_at IS NULL',
                ['user' => $adminId, 'batch' => $batchPublicId]
            );
            $this->events->record(
                $adminId,
                'security.recovery_codes.confirmed',
                'success',
                'high',
                'recovery_code_batch',
                $batchPublicId,
                ['confirmed' => false],
                ['confirmed' => true]
            );
        });
    }

    /** @return array{active:int,confirmed:int,used:int,batch_public_id:?string} */
    public function status(int $adminId): array
    {
        $latestBatch = $this->db->cell(
            'SELECT batch_public_id FROM security_recovery_codes
             WHERE user_id=:user ORDER BY created_at DESC,id DESC LIMIT 1',
            ['user' => $adminId]
        );
        if (!is_string($latestBatch) || $latestBatch === '') {
            return ['active' => 0, 'confirmed' => 0, 'used' => 0, 'batch_public_id' => null];
        }
        $row = $this->db->one(
            'SELECT
                COUNT(*) FILTER (WHERE used_at IS NULL AND revoked_at IS NULL) AS active,
                COUNT(*) FILTER (WHERE confirmed_at IS NOT NULL AND used_at IS NULL AND revoked_at IS NULL) AS confirmed,
                COUNT(*) FILTER (WHERE used_at IS NOT NULL) AS used
             FROM security_recovery_codes
             WHERE user_id=:user AND batch_public_id=:batch',
            ['user' => $adminId, 'batch' => $latestBatch]
        ) ?? ['active' => 0, 'confirmed' => 0, 'used' => 0];
        return [
            'active' => (int)$row['active'],
            'confirmed' => (int)$row['confirmed'],
            'used' => (int)$row['used'],
            'batch_public_id' => $latestBatch,
        ];
    }

    /**
     * @param callable(Database, array<string, mixed>):void $afterConsume
     * @return array<string, mixed>
     */
    public function consumeForRecovery(int $adminId, string $code, callable $afterConsume): array
    {
        $normalized = $this->normalize($code);
        if (strlen($normalized) !== 20) {
            throw new \RuntimeException('Kod odzyskiwania ma nieprawidłowy format.');
        }

        return $this->db->transaction(function (Database $db) use ($adminId, $normalized, $afterConsume): array {
            $rows = $db->all(
                'SELECT * FROM security_recovery_codes
                 WHERE user_id=:user AND confirmed_at IS NOT NULL
                   AND used_at IS NULL AND revoked_at IS NULL
                 ORDER BY id FOR UPDATE',
                ['user' => $adminId]
            );
            $matched = null;
            foreach ($rows as $row) {
                if (password_verify($normalized . env('PASSWORD_PEPPER', ''), (string)$row['code_hash'])) {
                    $matched = $row;
                    break;
                }
            }
            if ($matched === null) {
                $this->events->record(
                    $adminId,
                    'security.recovery.failed',
                    'failure',
                    'critical',
                    'user',
                    (string)$adminId,
                    null,
                    null,
                    'invalid_or_unconfirmed_recovery_code'
                );
                throw new \RuntimeException('Kod odzyskiwania jest nieprawidłowy, niepotwierdzony albo zużyty.');
            }

            $updated = $db->query(
                'UPDATE security_recovery_codes
                 SET used_at=NOW(),used_ip=:ip
                 WHERE id=:id AND used_at IS NULL AND revoked_at IS NULL',
                [
                    'id' => (int)$matched['id'],
                    'ip' => RequestContext::ipAddress(),
                ]
            )->rowCount();
            if ($updated !== 1) {
                throw new \RuntimeException('Kod odzyskiwania został już wykorzystany.');
            }
            $afterConsume($db, $matched);
            $db->query(
                'UPDATE security_recovery_codes SET revoked_at=NOW()
                 WHERE user_id=:user AND id<>:used_id AND used_at IS NULL AND revoked_at IS NULL',
                ['user' => $adminId, 'used_id' => (int)$matched['id']]
            );
            return $matched;
        });
    }

    private function randomCode(int $length): string
    {
        $result = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= self::ALPHABET[random_int(0, $max)];
        }
        return $result;
    }

    private function normalize(string $code): string
    {
        $code = strtoupper(trim($code));
        if (str_starts_with($code, 'D3-')) {
            $code = substr($code, 3);
        }
        return preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
    }
}
