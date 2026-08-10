<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuthorAgreementService
{
    public const ORGANIZATION = 'zrodlo-slowa';

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed> */
    public function requireActive(int $userId, ?int $expectedAgreementId = null): array
    {
        $agreement = $this->activeForUser($userId);
        if ($agreement === null) {
            throw new Dors3MobileException(
                'agreement_inactive',
                'Autor nie ma aktywnej umowy współpracy albo utracił uprawnienie do publikowania.',
                403,
            );
        }
        if ($expectedAgreementId !== null && (int)$agreement['id'] !== $expectedAgreementId) {
            throw new Dors3MobileException(
                'agreement_changed',
                'Umowa przypisana do urządzenia nie jest już bieżącą aktywną umową autora.',
                403,
            );
        }
        return $agreement;
    }

    /** @return array<string,mixed>|null */
    public function activeForUser(int $userId): ?array
    {
        $this->expireElapsed();
        return $this->db->one(
            'SELECT a.*,u.email,u.login_name,u.display_name
             FROM author_agreements a
             JOIN users u ON u.id=a.user_id
             WHERE a.user_id=:user
               AND a.organization_id=:organization
               AND a.status=\'active\'
               AND a.valid_from<=NOW()
               AND (a.valid_until IS NULL OR a.valid_until>NOW())
               AND u.status=\'active\'
               AND u.can_write=1
               AND (u.article_submit_blocked_until IS NULL OR u.article_submit_blocked_until<=NOW())
               AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'author\')
             ORDER BY a.valid_from DESC,a.id DESC
             LIMIT 1',
            ['user' => $userId, 'organization' => self::ORGANIZATION],
        );
    }

    /** @return list<array<string,mixed>> */
    public function searchEligible(string $variant, string $query = '', int $limit = 20): array
    {
        $this->expireElapsed();
        $variant = strtolower(trim($variant));
        if (!in_array($variant, ['admin', 'author'], true)) {
            throw new \InvalidArgumentException('Nieobsługiwany wariant aplikacji.');
        }
        $limit = max(1, min(50, $limit));
        $query = mb_substr(trim($query), 0, 120);
        $params = $query === '' ? [] : ['query' => '%' . $query . '%'];
        $identityFilter = $query === ''
            ? ''
            : ' AND (u.display_name ILIKE :query OR u.email ILIKE :query OR COALESCE(u.login_name,\'\') ILIKE :query)';

        if ($variant === 'admin') {
            return $this->db->all(
                'SELECT u.id,u.display_name,u.email,u.login_name,NULL::bigint AS agreement_id,NULL::varchar AS agreement_public_id
                 FROM users u
                 WHERE u.status=\'active\'
                   AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'admin\')'
                . $identityFilter
                . ' ORDER BY u.display_name,u.id LIMIT ' . $limit,
                $params,
            );
        }

        return $this->db->all(
            'SELECT u.id,u.display_name,u.email,u.login_name,a.id AS agreement_id,a.public_id AS agreement_public_id,
                    a.valid_from,a.valid_until,a.terms_version
             FROM users u
             JOIN author_agreements a ON a.user_id=u.id
             WHERE u.status=\'active\'
               AND u.can_write=1
               AND (u.article_submit_blocked_until IS NULL OR u.article_submit_blocked_until<=NOW())
               AND a.organization_id=:organization
               AND a.status=\'active\'
               AND a.valid_from<=NOW()
               AND (a.valid_until IS NULL OR a.valid_until>NOW())
               AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role=\'author\')'
            . $identityFilter
            . ' ORDER BY u.display_name,a.valid_from DESC,u.id LIMIT ' . $limit,
            $params + ['organization' => self::ORGANIZATION],
        );
    }

    public function expireElapsed(): int
    {
        return $this->db->query(
            'UPDATE author_agreements
             SET status=\'expired\',updated_at=NOW()
             WHERE organization_id=:organization
               AND status=\'active\'
               AND valid_until IS NOT NULL
               AND valid_until<=NOW()',
            ['organization' => self::ORGANIZATION],
        )->rowCount();
    }
}
