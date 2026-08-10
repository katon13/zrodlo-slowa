<?php
namespace App\Services;

use App\Core\Database;

final class UserDeletionService
{
    public function __construct(private readonly Database $db) {}

    public function report(int $userId): array
    {
        $user = $this->user($userId);
        if (!$user) {
            throw new \RuntimeException('Nie znaleziono użytkownika.');
        }

        $sections = [
            'Treści autora' => [
                ['key' => 'articles', 'label' => 'Artykuły', 'table' => 'articles', 'column' => 'author_id', 'action' => 'zostają jako treść systemu; autor może być zanonimizowany'],
                ['key' => 'article_events', 'label' => 'Zdarzenia tekstów', 'table' => 'article_events', 'column' => 'user_id', 'action' => 'anonimizacja / SET NULL tam, gdzie baza pozwala'],
            ],
            'Zakupy i finanse' => [
                ['key' => 'wallet_transactions', 'label' => 'Transakcje portfela', 'table' => 'wallet_transactions', 'column' => 'user_id', 'action' => 'nie usuwać; zostają jako historia księgowa bez danych osobowych'],
                ['key' => 'payments', 'label' => 'Płatności', 'table' => 'payments', 'column' => 'user_id', 'action' => 'nie usuwać przy realnej historii płatności'],
                ['key' => 'article_purchases_buyer', 'label' => 'Zakupy tekstów jako czytelnik', 'table' => 'article_purchases', 'column' => 'buyer_user_id', 'action' => 'nie usuwać przy historii finansowej'],
                ['key' => 'article_purchases_author', 'label' => 'Sprzedaże tekstów jako autor', 'table' => 'article_purchases', 'column' => 'author_user_id', 'action' => 'nie usuwać przy historii finansowej'],
                ['key' => 'payouts', 'label' => 'Wypłaty', 'table' => 'payouts', 'column' => 'user_id', 'action' => 'nie usuwać; zapis rozliczeniowy'],
                ['key' => 'article_access_grants', 'label' => 'Dostęp do treści', 'table' => 'article_access_grants', 'column' => 'user_id', 'action' => 'nie usuwać przy historii finansowej'],
            ],
            'Aktywność i bonusy' => [
                ['key' => 'app_referral_invitations_inviter', 'label' => 'Zaproszenia aplikacji wysłane', 'table' => 'app_referral_invitations', 'column' => 'inviter_user_id', 'action' => 'zachować jako historię promocji Talent'],
                ['key' => 'app_referral_invitations_invitee', 'label' => 'Zrealizowane zaproszenia aplikacji', 'table' => 'app_referral_invitations', 'column' => 'invitee_user_id', 'action' => 'zachować jako historię nagrody Talent i zanonimizować e-mail'],
                ['key' => 'activity_reward_logs', 'label' => 'Logi bonusów', 'table' => 'activity_reward_logs', 'column' => 'user_id', 'action' => 'czyszczenie techniczne albo anonimizacja'],
                ['key' => 'activity_bonus_notifications', 'label' => 'Komunikaty live zarobków', 'table' => 'activity_bonus_notifications', 'column' => 'user_id', 'action' => 'czyszczenie techniczne'],
                ['key' => 'user_activity_events', 'label' => 'Zdarzenia aktywności', 'table' => 'user_activity_events', 'column' => 'user_id', 'action' => 'czyszczenie techniczne'],
                ['key' => 'likes', 'label' => 'Polubienia', 'table' => 'likes', 'column' => 'user_id', 'action' => 'czyszczenie techniczne'],
                ['key' => 'shares', 'label' => 'Udostępnienia', 'table' => 'shares', 'column' => 'user_id', 'action' => 'czyszczenie techniczne'],
            ],
            'Ankiety i reklamy' => [
                ['key' => 'survey_responses', 'label' => 'Odpowiedzi w ankietach', 'table' => 'survey_responses', 'column' => 'user_id', 'action' => 'anonimizacja lub kasowanie techniczne'],
                ['key' => 'campaign_events', 'label' => 'Zdarzenia kampanii', 'table' => 'campaign_events', 'column' => 'user_id', 'action' => 'SET NULL / anonimizacja'],
                ['key' => 'ad_views', 'label' => 'Obejrzenia reklam', 'table' => 'ad_views', 'column' => 'user_id', 'action' => 'SET NULL / anonimizacja'],
                ['key' => 'ad_clicks', 'label' => 'Kliknięcia reklam', 'table' => 'ad_clicks', 'column' => 'user_id', 'action' => 'SET NULL / anonimizacja'],
                ['key' => 'sponsored_article_reads', 'label' => 'Czytanie sponsorowane', 'table' => 'sponsored_article_reads', 'column' => 'user_id', 'action' => 'SET NULL / anonimizacja'],
            ],
            'Dane techniczne konta' => [
                ['key' => 'password_resets', 'label' => 'Resety hasła', 'table' => 'password_resets', 'column' => 'user_id', 'action' => 'usuń'],
                ['key' => 'user_sessions', 'label' => 'Sesje użytkownika', 'table' => 'user_sessions', 'column' => 'user_id', 'action' => 'usuń'],
                ['key' => 'sessions', 'label' => 'Sesje', 'table' => 'sessions', 'column' => 'user_id', 'action' => 'usuń'],
                ['key' => 'remember_tokens', 'label' => 'Tokeny zapamiętania', 'table' => 'remember_tokens', 'column' => 'user_id', 'action' => 'usuń'],
            ],
        ];

        $total = 0;
        foreach ($sections as $group => $items) {
            foreach ($items as $i => $item) {
                $count = $this->countByColumn($item['table'], $item['column'], $userId);
                $sections[$group][$i]['count'] = $count;
                $sections[$group][$i]['exists'] = $this->tableHasColumn($item['table'], $item['column']);
                $total += $count;
            }
        }

        $hasFin = $this->hasFinancialHistory($userId);
        $hasPub = $this->countByColumn('articles', 'author_id', $userId) > 0;

        return [
            'user' => $user,
            'sections' => $sections,
            'total_dependencies' => $total,
            'has_financial_history' => $hasFin,
            'has_publication_history' => $hasPub,
            'can_hard_delete' => !$hasFin && !$hasPub,
            'recommended_mode' => (!$hasFin && !$hasPub) ? 'hard_clean_available' : 'anonymize',
        ];
    }

    public function anonymize(int $userId, int $adminId): array
    {
        if ($userId === $adminId) {
            throw new \RuntimeException('Nie można usunąć własnego konta administratora.');
        }
        $before = $this->report($userId);
        $token = date('YmdHis') . '_' . $userId;
        $deletedEmail = 'deleted_user_' . $token . '@example.local';
        $summary = [
            'mode' => 'anonymize',
            'user_id' => $userId,
            'financial_history_kept' => $before['has_financial_history'],
            'technical_cleanup' => [],
            'anonymized_tables' => [],
        ];

        $this->db->transaction(function(Database $db) use ($userId, $adminId, $deletedEmail, &$summary): void {
            foreach ([
                ['password_resets', 'user_id'],
                ['user_sessions', 'user_id'],
                ['sessions', 'user_id'],
                ['remember_tokens', 'user_id'],
                ['activity_bonus_notifications', 'user_id'],
            ] as [$table, $column]) {
                $deleted = $this->deleteWhere($db, $table, $column, $userId);
                if ($deleted !== null) {
                    $summary['technical_cleanup'][$table] = $deleted;
                }
            }

            foreach ([
                ['campaign_events', 'user_id'],
                ['ad_views', 'user_id'],
                ['ad_clicks', 'user_id'],
                ['sponsored_article_reads', 'user_id'],
                ['article_events', 'user_id'],
            ] as [$table, $column]) {
                $changed = $this->setNullWhere($db, $table, $column, $userId);
                if ($changed !== null) {
                    $summary['anonymized_tables'][$table] = $changed;
                }
            }

            $db->query('UPDATE users SET email=:email, phone=NULL, display_name=:name, password_hash=:hash, status=\'deleted\', can_write=0, talent_enabled=0, wallet_enabled=0, payout_enabled=0, permissions_updated_at=NOW(), deleted_at=NOW(), deleted_by_admin_id=:admin, deletion_mode=\'anonymized\', anonymized_at=NOW() WHERE id=:id', [
                'id' => $userId,
                'email' => $deletedEmail,
                'name' => 'Użytkownik usunięty #' . $userId,
                'hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'admin' => $adminId,
            ]);
            if ($this->tableHasColumn('app_referral_invitations', 'invitee_user_id')) {
                $db->query(
                    'UPDATE app_referral_invitations SET invited_email=:email,updated_at=NOW() WHERE invitee_user_id=:id',
                    ['email' => $deletedEmail, 'id' => $userId]
                );
            }

            $this->writeReport($db, $userId, $adminId, 'anonymize', $summary);
        });

        return $summary;
    }

    public function hardClean(int $userId, int $adminId, string $confirmation): array
    {
        if ($userId === $adminId) {
            throw new \RuntimeException('Nie można twardo usunąć własnego konta administratora.');
        }
        $expected = 'USUŃ UŻYTKOWNIKA ' . $userId;
        if (trim($confirmation) !== $expected) {
            throw new \RuntimeException('Nieprawidłowe potwierdzenie. Wpisz dokładnie: ' . $expected);
        }
        
        $hasFin = $this->hasFinancialHistory($userId);
        $hasPub = $this->countByColumn('articles', 'author_id', $userId) > 0;

        if ($hasFin) {
            throw new \RuntimeException('Ten użytkownik ma historię finansową. Użyj anonimizacji, a nie twardego czyszczenia.');
        }
        if ($hasPub) {
            throw new \RuntimeException('Ten użytkownik ma teksty w redakcji. Użyj anonimizacji, nie twardego usunięcia.');
        }

        $before = $this->report($userId);
        $summary = [
            'mode' => 'hard_clean',
            'user_id' => $userId,
            'deleted_tables' => [],
            'before' => $before,
        ];

        $this->db->transaction(function(Database $db) use ($userId, $adminId, &$summary): void {
            foreach ([
                ['password_resets', 'user_id'],
                ['user_sessions', 'user_id'],
                ['sessions', 'user_id'],
                ['remember_tokens', 'user_id'],
                ['activity_bonus_notifications', 'user_id'],
                ['activity_reward_logs', 'user_id'],
                ['user_activity_events', 'user_id'],
                ['survey_responses', 'user_id'],
                ['survey_response_items', 'user_id'],
                ['campaign_events', 'user_id'],
                ['ad_views', 'user_id'],
                ['ad_clicks', 'user_id'],
                ['sponsored_article_reads', 'user_id'],
                ['article_events', 'user_id'],
                ['article_reads', 'user_id'],
                ['likes', 'user_id'],
                ['shares', 'user_id'],
                ['user_roles', 'user_id'],
                ['user_profiles', 'user_id'],
                ['wallets', 'user_id'],
            ] as [$table, $column]) {
                $deleted = $this->deleteWhere($db, $table, $column, $userId);
                if ($deleted !== null) {
                    $summary['deleted_tables'][$table] = $deleted;
                }
            }

            $this->writeReport($db, $userId, $adminId, 'hard_clean', $summary);
            $db->query('DELETE FROM users WHERE id=:id', ['id' => $userId]);
        });

        return $summary;
    }

    public function recentReports(int $limit = 30): array
    {
        if (!$this->tableExists('user_delete_reports')) {
            return [];
        }
        return $this->db->all('SELECT r.*, u.display_name AS admin_name FROM user_delete_reports r LEFT JOIN users u ON u.id=r.deleted_by_admin_id ORDER BY r.created_at DESC, r.id DESC LIMIT ' . (int)$limit);
    }

    private function user(int $userId): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id=:id LIMIT 1', ['id' => $userId]);
    }

    private function hasFinancialHistory(int $userId): bool
    {
        $checks = [
            ['wallet_transactions', 'user_id'],
            ['payments', 'user_id'],
            ['article_purchases', 'buyer_user_id'],
            ['article_purchases', 'author_user_id'],
            ['payouts', 'user_id'],
            ['platform_revenues', 'buyer_user_id'],
            ['platform_revenues', 'author_user_id'],
            ['donations', 'user_id'],
            ['article_access_grants', 'user_id'],
            ['article_supports', 'reader_id'],
            ['article_supports', 'author_id'],
            ['app_referral_invitations', 'inviter_user_id'],
            ['app_referral_invitations', 'invitee_user_id'],
        ];
        foreach ($checks as [$table, $column]) {
            if ($this->countByColumn($table, $column, $userId) > 0) {
                return true;
            }
        }
        return false;
    }

    private function countByColumn(string $table, string $column, int $userId): int
    {
        if (!$this->tableHasColumn($table, $column)) {
            return 0;
        }
        $this->assertIdentifier($table);
        $this->assertIdentifier($column);
        return (int)$this->db->cell(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($table)
            . ' WHERE ' . $this->db->quoteIdentifier($column) . '=:id',
            ['id' => $userId]
        );
    }

    private function deleteWhere(Database $db, string $table, string $column, int $userId): ?int
    {
        if (!$this->tableHasColumn($table, $column)) {
            return null;
        }
        $this->assertIdentifier($table);
        $this->assertIdentifier($column);
        $quotedTable = $db->quoteIdentifier($table);
        $quotedColumn = $db->quoteIdentifier($column);
        $before = (int)$db->cell('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE ' . $quotedColumn . '=:id', ['id' => $userId]);
        $db->query('DELETE FROM ' . $quotedTable . ' WHERE ' . $quotedColumn . '=:id', ['id' => $userId]);
        return $before;
    }

    private function setNullWhere(Database $db, string $table, string $column, int $userId): ?int
    {
        if (!$this->tableHasColumn($table, $column) || !$this->columnNullable($table, $column)) {
            return null;
        }
        $this->assertIdentifier($table);
        $this->assertIdentifier($column);
        $quotedTable = $db->quoteIdentifier($table);
        $quotedColumn = $db->quoteIdentifier($column);
        $before = (int)$db->cell('SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE ' . $quotedColumn . '=:id', ['id' => $userId]);
        $db->query('UPDATE ' . $quotedTable . ' SET ' . $quotedColumn . '=NULL WHERE ' . $quotedColumn . '=:id', ['id' => $userId]);
        return $before;
    }

    private function writeReport(Database $db, int $userId, int $adminId, string $mode, array $summary): void
    {
        if (!$this->tableExists('user_delete_reports')) {
            return;
        }
        $db->insert('INSERT INTO user_delete_reports(deleted_user_id, deleted_by_admin_id, mode, summary_json, created_at) VALUES(:user,:admin,:mode,:summary,NOW())', [
            'user' => $userId,
            'admin' => $adminId,
            'mode' => $mode,
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }


    private function columnNullable(string $table, string $column): bool
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($column);
        return $this->db->columnNullable($table, $column);
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        $this->assertIdentifier($table);
        $this->assertIdentifier($column);
        return $this->db->columnExists($table, $column);
    }

    private function tableExists(string $table): bool
    {
        $this->assertIdentifier($table);
        return $this->db->tableExists($table);
    }

    private function assertIdentifier(string $value): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
            throw new \InvalidArgumentException('Nieprawidłowy identyfikator bazy.');
        }
    }
}
