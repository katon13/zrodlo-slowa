<?php
namespace App\Services;

use App\Core\Database;
use App\Security\Authorization\PermissionCatalog;

final class RoleService
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_READER = 'reader';
    public const ROLE_AUTHOR = 'author';
    public const ROLE_COMMENTATOR = 'commentator';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_CHIEF_EDITOR = 'chief_editor';
    public const ROLE_PUBLISHER = 'publisher';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_PROOFREADER = 'proofreader';
    public const ROLE_ACCOUNTANT = 'accountant';

    public function __construct(private readonly Database $db) {}

    public function editorialRoles(): array
    {
        return [
            self::ROLE_CHIEF_EDITOR => [
                'label' => 'REDAKTOR NACZELNY',
                'tile' => 'REDAKCJA GŁÓWNA',
                'description' => 'Decyzje Redakcji Głównej: submitted → review → approved albo rejected.',
                'requires_2fa' => true,
                'requires_verified_email' => true,
            ],
            self::ROLE_EDITOR => [
                'label' => 'EDYTOR',
                'tile' => 'EDYCJA TEKSTÓW',
                'description' => 'Tytuły, leady, kategorie, tagi i przygotowanie tekstów do publikacji.',
                'requires_2fa' => true,
                'requires_verified_email' => true,
            ],
            self::ROLE_PUBLISHER => [
                'label' => 'WYDAWCA',
                'tile' => 'WYDAWCA',
                'description' => 'Publikacja, wyróżnienia, premium, ceny i ekspozycja na stronie.',
                'requires_2fa' => true,
                'requires_verified_email' => true,
            ],
            self::ROLE_MODERATOR => [
                'label' => 'MODERATOR',
                'tile' => 'MODERATOR',
                'description' => 'Cena, premium, dostęp, promocja i zgoda na AI przy tekście.',
                'requires_2fa' => true,
                'requires_verified_email' => true,
            ],
            self::ROLE_PROOFREADER => [
                'label' => 'KOREKTOR',
                'tile' => 'KOREKTA',
                'description' => 'Korekta językowa, uwagi korektorskie i oznaczanie tekstów po korekcie.',
                'requires_2fa' => true,
                'requires_verified_email' => true,
            ],
            self::ROLE_ACCOUNTANT => [
                'label' => 'KSIĘGOWA',
                'tile' => 'FINANSE',
                'description' => 'Wypłaty, rozliczenia, podział Autor / Serwis / Safety Fund, raporty finansowe i kontrola wypłat.',
                'requires_2fa' => true,
                'requires_verified_email' => true,
            ],
        ];
    }

    public function panelDefinitions(): array
    {
        return [
            'chief_editor' => [
                'title' => 'REDAKCJA GŁÓWNA',
                'role' => self::ROLE_CHIEF_EDITOR,
                'route' => '/admin/role-panel?panel=chief_editor',
                'description' => 'Teksty od autora: submitted → review → approved albo rejected.',
                'target' => 'articles',
            ],
            'editor' => [
                'title' => 'EDYCJA TEKSTÓW',
                'role' => self::ROLE_EDITOR,
                'route' => '/admin/editorial',
                'description' => 'Pełna kontrola treści, zdjęć, kolejności, ważności oraz dyspozycji do tłumaczeń.',
                'target' => 'articles',
            ],
            'publisher' => [
                'title' => 'WYDAWCA',
                'role' => self::ROLE_PUBLISHER,
                'route' => '/admin/editorial',
                'description' => 'Kolejność, czas publikacji, miejsce w siatce i zgoda wydawnicza.',
                'target' => 'articles',
            ],
            'moderator' => [
                'title' => 'MODERACJA TEKSTÓW',
                'role' => self::ROLE_MODERATOR,
                'route' => '/admin/role-panel?panel=moderator',
                'description' => 'Cena, premium, promocja, dostęp i zgoda na AI przy tekście.',
                'target' => 'articles',
            ],
            'proofreader' => [
                'title' => 'KOREKTA',
                'role' => self::ROLE_PROOFREADER,
                'route' => '/admin/role-panel?panel=proofreader',
                'description' => 'Lista tekstów do korekty oraz edycja leadu i treści.',
                'target' => 'articles',
            ],
            'accountant' => [
                'title' => 'FINANSE',
                'role' => self::ROLE_ACCOUNTANT,
                'route' => '/admin/role-panel?panel=accountant',
                'description' => 'Wypłaty, saldo, prowizje i kontrola ryzyka.',
                'target' => 'payouts',
            ],
        ];
    }

    public function rolesForUser(int $userId): array
    {
        $rows = $this->db->all('SELECT role FROM user_roles WHERE user_id=:id ORDER BY role', ['id' => $userId]);
        return array_values(array_map(static fn(array $row): string => (string)$row['role'], $rows));
    }

    public function permissionsForUser(int $userId): array
    {
        return PermissionCatalog::forRoles($this->rolesForUser($userId));
    }

    public function userHasPermission(int $userId, string $permission): bool
    {
        return PermissionCatalog::allows($this->rolesForUser($userId), $permission);
    }

    public function listUsersForRoleAdmin(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $rolesAggregate = $this->db->isPostgres()
            ? "(SELECT STRING_AGG(ur.role, ',' ORDER BY ur.role)
                FROM user_roles ur WHERE ur.user_id=u.id)"
            : "(SELECT GROUP_CONCAT(ur.role ORDER BY ur.role SEPARATOR ',')
                FROM user_roles ur WHERE ur.user_id=u.id)";
        return $this->db->all('
            SELECT
                u.id,
                u.email,
                u.display_name,
                u.status,
                u.created_at,
                u.email_verified_at,
                u.two_factor_enabled,
                u.force_2fa_setup,
                u.auth_security_level,
                ' . $rolesAggregate . ' AS roles
            FROM users u
            WHERE u.status != \'deleted\'
            ORDER BY u.created_at DESC, u.id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset);
    }

    public function syncEditorialRoles(int $userId, array $selectedRoles, int $adminId): array
    {
        $user = $this->db->one('SELECT id, status FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
        if (!$user) {
            throw new \RuntimeException('Nie znaleziono użytkownika.');
        }
        if ((string)$user['status'] === 'deleted') {
            throw new \RuntimeException('Nie można zmieniać ról konta, które zostało zanonimizowane.');
        }

        $allowed = array_keys($this->editorialRoles());
        $selected = array_values(array_unique(array_intersect($allowed, array_map('strval', $selectedRoles))));
        $current = $this->rolesForUser($userId);
        $currentEditorial = array_values(array_intersect($allowed, $current));

        sort($selected);
        sort($currentEditorial);
        if ($selected === $currentEditorial) {
            return ['added' => [], 'removed' => [], 'selected' => $selected];
        }

        $this->db->transaction(function(Database $db) use ($userId, $selected, $allowed): void {
            foreach ($allowed as $role) {
                $db->query('DELETE FROM user_roles WHERE user_id=:id AND role=:role', ['id' => $userId, 'role' => $role]);
            }
            foreach ($selected as $role) {
                $db->query('INSERT INTO user_roles(user_id,role) VALUES(:id,:role)', ['id' => $userId, 'role' => $role]);
            }
            $db->query(
                'UPDATE users SET session_version=session_version+1,updated_at=NOW() WHERE id=:id',
                ['id' => $userId]
            );
        });

        $added = array_values(array_diff($selected, $currentEditorial));
        $removed = array_values(array_diff($currentEditorial, $selected));

        if ($selected !== []) {
            $this->db->query('UPDATE users SET auth_security_level=\'high\', force_2fa_setup=1, updated_at=NOW() WHERE id=:id', ['id' => $userId]);
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'selected' => $selected,
            'admin_id' => $adminId,
        ];
    }

    public function panelsForRoles(array $roles, bool $isAdmin = false): array
    {
        $panels = [];
        foreach ($this->panelDefinitions() as $code => $panel) {
            if ($isAdmin || in_array($panel['role'], $roles, true)) {
                $panels[$code] = $panel;
            }
        }
        return $panels;
    }

    public function canAccessPanel(int $userId, string $panelCode, bool $isAdmin = false): bool
    {
        $panels = $this->panelDefinitions();
        if (!isset($panels[$panelCode])) {
            return false;
        }
        if ($isAdmin) {
            return true;
        }
        return in_array($panels[$panelCode]['role'], $this->rolesForUser($userId), true);
    }

    public function panelRows(string $panelCode, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        return match ($panelCode) {
            'chief_editor' => $this->articleRows(['submitted', 'review', 'approved'], $limit, $offset),
            'editor' => $this->articleRows(['submitted', 'review', 'approved'], $limit, $offset),
            'publisher' => $this->articleRows(['approved', 'published', 'archived'], $limit, $offset),
            'moderator' => $this->articleRows(['approved', 'published', 'archived'], $limit, $offset),
            'proofreader' => (new ArticleService($this->db))->allForProofreading($limit, $offset),
            'accountant' => $this->payoutRows($limit, $offset),
            default => [],
        };
    }


    private function articleRows(array $statuses, int $limit, int $offset): array
    {
        $allowed = ['draft', 'submitted', 'review', 'approved', 'published', 'rejected', 'archived'];
        $statuses = array_values(array_intersect($allowed, $statuses));
        if ($statuses === []) {
            $statuses = ['submitted'];
        }
        $placeholders = [];
        $params = [];
        foreach ($statuses as $i => $status) {
            $key = 's' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }
        return $this->db->all('
            SELECT a.id, a.author_id, a.title, a.status, a.price_minor, a.currency, a.is_premium, a.is_unique, a.article_label, a.access_mode, a.pricing_status, a.author_share_percent, a.platform_share_percent, a.editor_valuation_note, a.updated_at, a.created_at, a.published_at, a.source_language, u.display_name AS author_name, u.article_submit_blocked_until, u.article_submit_block_reason
            FROM articles a
            JOIN users u ON u.id=a.author_id
            WHERE a.status IN (' . implode(',', $placeholders) . ')
            ORDER BY a.updated_at DESC, a.id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    private function payoutRows(int $limit, int $offset): array
    {
        return $this->db->all('
            SELECT p.id, p.user_id, p.amount_minor, p.currency, p.status, p.requested_at, u.display_name, u.email
            FROM payouts p
            JOIN users u ON u.id=p.user_id
            ORDER BY CASE WHEN p.status=\'pending\' THEN 0 ELSE 1 END, p.requested_at DESC, p.id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset
        );
    }
}
