<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class Dors3MobileOperationExecutor
{
    /** @param array<string,mixed> $request @param array<string,mixed> $payload */
    public function execute(Database $db, array $request, array $payload): void
    {
        $actionType = (string)$request['action_type'];
        $issuedAt = (int)$request['issued_at_epoch'];
        $fingerprints = new Dors3OperationFingerprintService($db);

        if ($actionType === 'role.change') {
            $adminId = (int)($payload['admin_id'] ?? 0);
            $targetUserId = (int)($payload['target_user_id'] ?? 0);
            $kind = (string)($payload['kind'] ?? '');
            if ($adminId !== (int)$request['user_id'] || $targetUserId <= 0) {
                throw new \RuntimeException('Aktor lub użytkownik zmiany roli nie zgadza się z podpisanym żądaniem.');
            }
            $this->requireActiveRole($db, $adminId, ['admin'], 'admin_role_revoked');
            $beforeRoles = array_map(
                static fn(array $row): string => (string)$row['role'],
                $db->all('SELECT role FROM user_roles WHERE user_id=:id ORDER BY role', ['id' => $targetUserId])
            );
            if ($kind === 'primary_role') {
                $targetRole = (string)($payload['target_role'] ?? '');
                $current = $fingerprints->adminCritical(
                    'role.change',
                    $adminId,
                    'user',
                    (string)$targetUserId,
                    ['kind' => $kind, 'target_role' => $targetRole],
                    ['roles' => $beforeRoles],
                    ['primary_role' => $targetRole],
                    $issuedAt,
                );
                $this->assertFingerprint((string)$request['action_fingerprint'], $current['fingerprint']);
                (new UserService($db))->setPrimaryRole($targetUserId, $targetRole);
                return;
            }
            if ($kind === 'editorial_roles') {
                $targetRoles = array_values(array_map('strval', is_array($payload['target_roles'] ?? null) ? $payload['target_roles'] : []));
                sort($targetRoles, SORT_STRING);
                $current = $fingerprints->adminCritical(
                    'role.change',
                    $adminId,
                    'user',
                    (string)$targetUserId,
                    ['kind' => $kind],
                    ['roles' => $beforeRoles],
                    ['requested_editorial_roles' => $targetRoles],
                    $issuedAt,
                );
                $this->assertFingerprint((string)$request['action_fingerprint'], $current['fingerprint']);
                (new RoleService($db))->syncEditorialRoles($targetUserId, $targetRoles, $adminId);
                return;
            }
            throw new \RuntimeException('Nieobsługiwany rodzaj zmiany roli.');
        }

        if ($actionType === 'article.submit') {
            $articleId = (int)($payload['article_id'] ?? 0);
            $authorId = (int)($payload['author_id'] ?? 0);
            if ($authorId !== (int)$request['user_id']) {
                throw new \RuntimeException('Autor operacji nie zgadza się z podpisanym żądaniem.');
            }
            $this->requireActiveJournalist($db, $authorId);
            $current = $fingerprints->articleSubmit($articleId, $authorId, $issuedAt);
            $this->assertFingerprint((string)$request['action_fingerprint'], $current['fingerprint']);
            (new ArticleService($db))->submit($articleId, $authorId);
            return;
        }

        if ($actionType === 'article.publish') {
            $articleId = (int)($payload['article_id'] ?? 0);
            $authorId = (int)($payload['author_id'] ?? 0);
            if ($authorId !== (int)$request['user_id']) {
                throw new \RuntimeException('Autor publikacji nie zgadza się z podpisanym żądaniem.');
            }
            $this->requireActiveJournalist($db, $authorId);
            $this->requireActiveRole($db, $authorId, ['publisher', 'chief_editor'], 'publisher_role_revoked');
            $current = $fingerprints->articlePublish($articleId, $authorId, $issuedAt);
            $this->assertFingerprint((string)$request['action_fingerprint'], $current['fingerprint']);
            (new ArticleService($db))->setStatus($articleId, 'published', $authorId);
            return;
        }

        if (in_array($actionType, ['payout.approve', 'payout.reject'], true)) {
            $payoutId = (int)($payload['payout_id'] ?? 0);
            $targetStatus = (string)($payload['target_status'] ?? '');
            $adminId = (int)($payload['admin_id'] ?? 0);
            if ($adminId !== (int)$request['user_id']) {
                throw new \RuntimeException('Administrator operacji nie zgadza się z podpisanym żądaniem.');
            }
            $this->requireActiveRole($db, $adminId, ['admin'], 'admin_role_revoked');
            $current = $fingerprints->payoutStatus($payoutId, $targetStatus, $issuedAt);
            $this->assertFingerprint((string)$request['action_fingerprint'], $current['fingerprint']);
            $payout = $db->one('SELECT * FROM payouts WHERE id=:id FOR UPDATE', ['id' => $payoutId]);
            if ($payout === null) {
                throw new \RuntimeException('Wypłata nie istnieje.');
            }
            $recipientEligible = (int)($db->cell(
                'SELECT COUNT(*) FROM users
                 WHERE id=:id AND status=\'active\' AND wallet_enabled=1 AND payout_enabled=1',
                ['id' => (int)$payout['user_id']],
            ) ?: 0);
            if ($recipientEligible !== 1) {
                throw new Dors3MobileException(
                    'payout_recipient_not_eligible',
                    'Odbiorca utracił uprawnienie do wypłat przed wykonaniem zatwierdzenia.',
                    409,
                );
            }
            (new FinancialService($db))->requestApproval(
                'payout_status_update',
                (int)$payout['amount_minor'],
                (string)$payout['currency'],
                0,
                (int)$payout['user_id'],
                [
                    'payout_id' => $payoutId,
                    'target_status' => $targetStatus,
                    'admin_note' => (string)($payload['admin_note'] ?? ''),
                    'dors3_mobile_approval_request_id' => (string)$request['public_id'],
                ],
                "Zmiana statusu wypłaty #{$payoutId} na {$targetStatus} zatwierdzona przez 3DORS Mobile.",
                ['id' => $adminId, 'role' => 'admin'],
                'dors3_mobile',
                (string)$request['public_id'],
                (string)($request['correlation_id'] ?? ''),
            );
            return;
        }

        if ($actionType === 'financial_settings.change') {
            $adminId = (int)($payload['admin_id'] ?? 0);
            $authorBasisPoints = (int)($payload['author_basis_points'] ?? -1);
            $platformBasisPoints = (int)($payload['platform_basis_points'] ?? -1);
            $safetyFundBasisPoints = (int)($payload['safety_fund_basis_points'] ?? -1);
            if ($adminId !== (int)$request['user_id']) {
                throw new \RuntimeException('safety_fund.error.actor_mismatch');
            }
            $this->requireActiveRole($db, $adminId, ['admin'], 'admin_role_revoked');
            $current = $fingerprints->revenueSplitPolicy(
                $adminId,
                $authorBasisPoints,
                $platformBasisPoints,
                $safetyFundBasisPoints,
                $issuedAt,
            );
            $this->assertFingerprint((string)$request['action_fingerprint'], $current['fingerprint']);
            (new SafetyFundService($db))->activatePolicy(
                $adminId,
                $authorBasisPoints,
                $platformBasisPoints,
                $safetyFundBasisPoints,
                (string)$request['public_id'],
            );
            return;
        }

        if ($actionType === 'safety_fund.disbursement') {
            $adminId = (int)($payload['admin_id'] ?? 0);
            $publicId = (string)($payload['public_id'] ?? '');
            $amountMinor = (int)($payload['amount_minor'] ?? 0);
            $category = (string)($payload['category'] ?? '');
            $description = (string)($payload['description'] ?? '');
            $evidenceReference = (string)($payload['evidence_reference'] ?? '');
            if ($adminId !== (int)$request['user_id'] || $publicId === '') {
                throw new \RuntimeException('safety_fund.error.actor_mismatch');
            }
            $this->requireActiveRole($db, $adminId, ['admin'], 'admin_role_revoked');
            $current = $fingerprints->safetyFundDisbursement(
                $adminId,
                $publicId,
                $amountMinor,
                $category,
                $description,
                $evidenceReference,
                $issuedAt,
            );
            $this->assertFingerprint((string)$request['action_fingerprint'], $current['fingerprint']);
            (new SafetyFundService($db))->executeDisbursement(
                $adminId,
                $publicId,
                $amountMinor,
                $category,
                $description,
                $evidenceReference,
                (string)$request['public_id'],
                $issuedAt,
            );
            return;
        }

        throw new \RuntimeException('Brak wykonawcy dla podpisanej operacji: ' . $actionType);
    }

    private function assertFingerprint(string $expected, string $actual): void
    {
        if (!hash_equals($expected, $actual)) {
            throw new Dors3MobileException('fingerprint_mismatch', 'Treść operacji zmieniła się po podpisaniu.', 409);
        }
    }

    /** @param list<string> $roles */
    private function requireActiveRole(Database $db, int $userId, array $roles, string $errorCode): void
    {
        $user = $db->one('SELECT status FROM users WHERE id=:id FOR SHARE', ['id' => $userId]);
        $placeholders = [];
        $params = ['user' => $userId];
        foreach ($roles as $index => $role) {
            $key = 'role_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $role;
        }
        $hasRole = $user !== null && (string)$user['status'] === 'active' && (int)($db->cell(
            'SELECT COUNT(*) FROM user_roles WHERE user_id=:user AND role IN (' . implode(',', $placeholders) . ')',
            $params,
        ) ?: 0) > 0;
        if (!$hasRole) {
            throw new Dors3MobileException($errorCode, 'Wymagana rola została cofnięta przed wykonaniem operacji.', 403);
        }
    }

    private function requireActiveJournalist(Database $db, int $userId): void
    {
        $user = $db->one('SELECT status,can_write FROM users WHERE id=:id FOR SHARE', ['id' => $userId]);
        if ($user === null || (string)$user['status'] !== 'active' || (int)$user['can_write'] !== 1) {
            throw new Dors3MobileException('journalist_role_revoked', 'Użytkownik nie ma już aktywnego uprawnienia dziennikarza.', 403);
        }
        $this->requireActiveRole($db, $userId, ['author'], 'journalist_role_revoked');
        (new AuthorAgreementService($db))->requireActive($userId);
    }
}
